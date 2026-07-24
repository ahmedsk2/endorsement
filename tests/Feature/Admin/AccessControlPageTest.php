<?php

namespace Tests\Feature\Admin;

use App\Models\Capability;
use App\Models\RoleCapability;
use App\Models\User;
use App\Models\UserCapability;
use App\Support\AccessControl;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * B6 — Admin → Access Control page: edit role defaults + per-user overrides at runtime.
 *
 * The page and every write endpoint are admin-only (`cap:access.manage`). Every write is
 * audited (PHI-free) and busts the resolver cache correctly (generation bump for role
 * changes, per-user forget for user overrides).
 */
class AccessControlPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    private function capId(string $key): int
    {
        return (int) Capability::where('key', $key)->value('id');
    }

    public function test_administrator_can_open_the_access_control_page(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)
            ->get('/admin/access-control')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/AccessControl')
                ->has('capabilities')
                ->has('positions')
                ->has('roleMatrix')
                ->has('users')
            );
    }

    public function test_administrator_role_cannot_give_up_access_manage(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $accessManageId = $this->capId('access.manage');

        // Submit a role update for position 0 that omits access.manage.
        $withoutAccessManage = Capability::where('key', '!=', 'access.manage')->pluck('id')->all();

        $this->actingAs($admin)
            ->from('/admin/access-control')
            ->put('/admin/access-control/role', [
                'position' => 0,
                'capability_ids' => $withoutAccessManage,
            ])
            ->assertSessionHasErrors('capability_ids');

        // access.manage is still granted to the Administrator role (not removed).
        $this->assertDatabaseHas('role_capabilities', [
            'position' => 0,
            'capability_id' => $accessManageId,
        ]);
    }

    public function test_a_nurse_is_forbidden_and_an_access_denied_row_is_written(): void
    {
        $nurse = User::factory()->create(['position' => 1]);

        $this->actingAs($nurse)
            ->get('/admin/access-control')
            ->assertForbidden();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'access_denied',
            'user_id' => $nurse->id,
            'detail' => 'cap=access.manage',
        ]);
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get('/admin/access-control')->assertRedirect('/login');
    }

    public function test_update_role_persists_added_and_removed_capabilities_and_audits(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $nurse = User::factory()->create(['position' => 1]);

        // Nurse (position 1) holds only profile.manage — not endorsement.edit.
        $this->assertTrue(AccessControl::allows($nurse, 'profile.manage'));
        $this->assertFalse(AccessControl::allows($nurse, 'endorsement.edit'));

        // New desired set: current minus profile.manage, plus endorsement.edit.
        $current = RoleCapability::where('position', 1)->pluck('capability_id')->all();
        $desired = array_values(array_diff($current, [$this->capId('profile.manage')]));
        $desired[] = $this->capId('endorsement.edit');

        $this->actingAs($admin)
            ->put('/admin/access-control/role', [
                'position' => 1,
                'capability_ids' => $desired,
            ])
            ->assertRedirect();

        // The role_capabilities rows now match exactly (added row present, removed row gone).
        $this->assertDatabaseHas('role_capabilities', [
            'position' => 1,
            'capability_id' => $this->capId('endorsement.edit'),
        ]);
        $this->assertDatabaseMissing('role_capabilities', [
            'position' => 1,
            'capability_id' => $this->capId('profile.manage'),
        ]);

        // The change is reflected through the resolver (cache busted via generation bump).
        $this->assertTrue(AccessControl::allows($nurse, 'endorsement.edit'));
        $this->assertFalse(AccessControl::allows($nurse, 'profile.manage'));

        $this->assertDatabaseHas('audit_log', [
            'action' => 'access_role_update',
            'user_id' => $admin->id,
            'detail' => 'position=1;caps='.count($desired),
        ]);
    }

    public function test_update_role_rejects_an_unknown_position(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)
            ->from('/admin/access-control')
            ->put('/admin/access-control/role', [
                'position' => 9,
                'capability_ids' => [$this->capId('profile.manage')],
            ])
            ->assertSessionHasErrors('position');
    }

    public function test_update_role_rejects_an_unknown_capability_id(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)
            ->from('/admin/access-control')
            ->put('/admin/access-control/role', [
                'position' => 1,
                'capability_ids' => [999999],
            ])
            ->assertSessionHasErrors('capability_ids.0');
    }

    public function test_a_nurse_cannot_edit_role_defaults(): void
    {
        $nurse = User::factory()->create(['position' => 1]);

        $this->actingAs($nurse)
            ->put('/admin/access-control/role', [
                'position' => 1,
                'capability_ids' => [$this->capId('endorsement.reopen')],
            ])
            ->assertForbidden();
    }

    public function test_update_user_persists_a_grant_and_a_deny_and_audits(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $nurse = User::factory()->create(['position' => 1]);

        $this->actingAs($admin)
            ->put('/admin/access-control/user', [
                'user_id' => $nurse->id,
                'overrides' => [
                    $this->capId('endorsement.reopen') => 'grant',
                    $this->capId('profile.manage') => 'deny',
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_capabilities', [
            'user_id' => $nurse->id,
            'capability_id' => $this->capId('endorsement.reopen'),
            'effect' => 'grant',
        ]);
        $this->assertDatabaseHas('user_capabilities', [
            'user_id' => $nurse->id,
            'capability_id' => $this->capId('profile.manage'),
            'effect' => 'deny',
        ]);

        // End-to-end: the grant adds endorsement.reopen, and the deny removes an otherwise
        // role-granted profile.manage (deny wins).
        $this->assertTrue(AccessControl::allows($nurse, 'endorsement.reopen'));
        $this->assertFalse(AccessControl::allows($nurse, 'profile.manage'));

        $this->assertDatabaseHas('audit_log', [
            'action' => 'access_user_update',
            'user_id' => $admin->id,
            'detail' => 'user='.$nurse->id.';overrides=2',
        ]);
    }

    public function test_update_user_removes_overrides_omitted_from_the_submitted_set(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $nurse = User::factory()->create(['position' => 1]);

        // Pre-existing grant that should be removed when omitted from a fresh submission.
        UserCapability::create([
            'user_id' => $nurse->id,
            'capability_id' => $this->capId('endorsement.reopen'),
            'effect' => 'grant',
        ]);
        AccessControl::flush($nurse->id);
        $this->assertTrue(AccessControl::allows($nurse, 'endorsement.reopen'));

        // Submit an empty override set -> all explicit overrides for the user are cleared.
        $this->actingAs($admin)
            ->put('/admin/access-control/user', [
                'user_id' => $nurse->id,
                'overrides' => [],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('user_capabilities', [
            'user_id' => $nurse->id,
            'capability_id' => $this->capId('endorsement.reopen'),
        ]);
        $this->assertFalse(AccessControl::allows($nurse, 'endorsement.reopen'));
    }

    public function test_update_user_rejects_an_unknown_effect(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $nurse = User::factory()->create(['position' => 1]);

        $this->actingAs($admin)
            ->from('/admin/access-control')
            ->put('/admin/access-control/user', [
                'user_id' => $nurse->id,
                'overrides' => [$this->capId('endorsement.reopen') => 'sometimes'],
            ])
            ->assertSessionHasErrors('overrides.'.$this->capId('endorsement.reopen'));
    }

    public function test_a_nurse_cannot_edit_user_overrides(): void
    {
        $nurse = User::factory()->create(['position' => 1]);
        $victim = User::factory()->create(['position' => 1]);

        $this->actingAs($nurse)
            ->put('/admin/access-control/user', [
                'user_id' => $victim->id,
                'overrides' => [$this->capId('endorsement.reopen') => 'grant'],
            ])
            ->assertForbidden();
    }

    public function test_selecting_a_user_returns_their_effective_caps_and_overrides(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $nurse = User::factory()->create(['position' => 1]);

        UserCapability::create([
            'user_id' => $nurse->id,
            'capability_id' => $this->capId('endorsement.reopen'),
            'effect' => 'grant',
        ]);

        $this->actingAs($admin)
            ->get('/admin/access-control?user_id='.$nurse->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/AccessControl')
                ->where('selectedUser.id', $nurse->id)
                ->where('selectedUser.overrides.'.$this->capId('endorsement.reopen'), 'grant')
                ->has('selectedUser.effective')
            );
    }

    public function test_role_update_busts_the_cache_by_generation_without_flushing_unrelated_cache(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $nurse = User::factory()->create(['position' => 1]);

        // Warm the resolver cache for the nurse.
        $this->assertFalse(AccessControl::allows($nurse, 'endorsement.edit'));

        // Unrelated cache state that MUST survive a role update (e.g. a rate-limiter counter
        // and an arbitrary cached value). A global Cache::flush() would wipe these.
        Cache::put('unrelated.value', 'keep-me', 600);
        RateLimiter::hit('probe-key', 600);
        $this->assertSame(1, RateLimiter::attempts('probe-key'));

        // Grant endorsement.edit to the nurse's role.
        $desired = RoleCapability::where('position', 1)->pluck('capability_id')->all();
        $desired[] = $this->capId('endorsement.edit');

        $this->actingAs($admin)
            ->put('/admin/access-control/role', [
                'position' => 1,
                'capability_ids' => $desired,
            ])
            ->assertRedirect();

        // The role change is visible (cache busted via generation), and the unrelated cache
        // entries are intact (no Cache::flush()).
        $this->assertTrue(AccessControl::allows($nurse, 'endorsement.edit'));
        $this->assertSame('keep-me', Cache::get('unrelated.value'));
        $this->assertSame(1, RateLimiter::attempts('probe-key'));
    }
}
