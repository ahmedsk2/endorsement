<?php

namespace Tests\Feature\Admin;

use App\Models\PendingRegistration;
use App\Models\User;
use App\Support\AccessControl;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * F1 — Admin → Users (re-platform of legacy control.php): approve/reject pending
 * self-registrations, activate/deactivate accounts, and change roles. Every route is
 * `auth` + `cap:users.manage` (admin-only per the seeded matrix). The approval copies the
 * pending row's ALREADY-HASHED password verbatim (never re-hashed), every write is audited
 * PHI-free (ids only), and the last remaining active Administrator can be neither
 * deactivated nor demoted (self-lockout guard).
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['position' => 0]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pending(array $overrides = []): PendingRegistration
    {
        return PendingRegistration::create(array_merge([
            'full_name' => 'Pending Person',
            'member_name' => 'pending.'.fake()->unique()->userName(),
            'member_email' => fake()->unique()->safeEmail(),
            'position' => 4,
            'password' => 'plain-secret-123', // hashed once by the model cast
            'requested_at' => now(),
            // Approval now REFUSES an unconfirmed address, so the default fixture is a
            // registration that completed its email confirmation. Override with null to
            // exercise the refusal.
            'email_verified_at' => now(),
        ], $overrides));
    }

    // ---------------------------------------------------------------- page access

    public function test_administrator_can_open_the_users_page_with_pending_and_users_lists(): void
    {
        $admin = $this->admin();
        $pending = $this->pending();

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users')
                ->has('pending', 1)
                ->where('pending.0.id', $pending->id)
                ->where('pending.0.member_name', $pending->member_name)
                ->has('users', 1)
                ->where('users.0.id', $admin->id)
                ->where('users.0.position', 0)
                ->where('users.0.active', true)
                ->where('users.0.has_two_factor', false)
            );
    }

    /**
     * Task 6 / Decision A — Users.vue's deleted `fmt()` helper called
     * `new Date(iso).toLocaleString()` on this field, which renders in the BROWSER's own locale
     * and timezone rather than the instance's. The server now sends an already-formatted
     * string and the client does no date construction at all.
     */
    public function test_pending_requested_at_arrives_already_formatted(): void
    {
        $pending = $this->pending(['requested_at' => '2026-07-10 14:05:00']);

        $this->actingAs($this->admin())
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pending.0.requested_at', $pending->fresh()->requested_at->format('Y-m-d H:i'))
            );
    }

    public function test_the_pending_list_never_ships_the_password_hash(): void
    {
        $this->pending();

        $this->actingAs($this->admin())
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('pending.0.password')
                ->missing('users.0.password')
            );
    }

    public function test_a_nurse_is_forbidden_from_the_users_page(): void
    {
        $nurse = User::factory()->create(['position' => 4]);

        $this->actingAs($nurse)->get('/admin/users')->assertForbidden();
    }

    public function test_the_users_page_requires_authentication(): void
    {
        $this->get('/admin/users')->assertRedirect('/login');
    }

    // ---------------------------------------------------------------- approve

    public function test_approve_creates_an_active_user_with_the_hash_copied_verbatim(): void
    {
        $admin = $this->admin();
        $pending = $this->pending();
        $pendingHash = $pending->getRawOriginal('password');

        $this->actingAs($admin)
            ->post('/admin/users/pending/'.$pending->id.'/approve')
            ->assertRedirect();

        $user = User::where('member_name', $pending->member_name)->first();
        $this->assertNotNull($user);
        $this->assertTrue((bool) $user->active);
        $this->assertSame($pending->full_name, $user->full_name);
        $this->assertSame($pending->member_email, $user->member_email);
        $this->assertSame(4, $user->position);

        // The stored hash is byte-for-byte the pending hash (never re-derived) AND the
        // original plaintext still verifies against it.
        $this->assertSame($pendingHash, $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('plain-secret-123', $user->getRawOriginal('password')));

        // The pending row is consumed.
        $this->assertDatabaseMissing('pending_registrations', ['id' => $pending->id]);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'user_approve',
            'user_id' => $admin->id,
            'detail' => 'pending='.$pending->id.';user='.$user->id,
        ]);
    }

    public function test_approve_returns_422_when_the_member_name_was_taken_since_registration(): void
    {
        $pending = $this->pending(['member_name' => 'collide.name']);
        // A user created AFTER the registration request now holds the same member_name.
        User::factory()->create(['member_name' => 'collide.name']);

        $this->actingAs($this->admin())
            ->postJson('/admin/users/pending/'.$pending->id.'/approve')
            ->assertStatus(422)
            ->assertJsonValidationErrors('member_name');

        // Nothing was consumed and no duplicate user was created.
        $this->assertDatabaseHas('pending_registrations', ['id' => $pending->id]);
        $this->assertSame(2, User::withTrashed()->count()); // the admin + the collider
    }

    public function test_approve_returns_422_when_the_member_email_was_taken_since_registration(): void
    {
        $pending = $this->pending(['member_email' => 'collide@example.com']);
        User::factory()->create(['member_email' => 'collide@example.com']);

        $this->actingAs($this->admin())
            ->postJson('/admin/users/pending/'.$pending->id.'/approve')
            ->assertStatus(422)
            ->assertJsonValidationErrors('member_email');

        $this->assertDatabaseHas('pending_registrations', ['id' => $pending->id]);
    }

    public function test_a_nurse_cannot_approve_a_pending_registration(): void
    {
        $nurse = User::factory()->create(['position' => 4]);
        $pending = $this->pending();

        $this->actingAs($nurse)
            ->post('/admin/users/pending/'.$pending->id.'/approve')
            ->assertForbidden();

        $this->assertDatabaseHas('pending_registrations', ['id' => $pending->id]);
    }

    // ---------------------------------------------------------------- reject

    public function test_reject_deletes_the_pending_row_and_audits(): void
    {
        $admin = $this->admin();
        $pending = $this->pending();

        $this->actingAs($admin)
            ->delete('/admin/users/pending/'.$pending->id)
            ->assertRedirect();

        $this->assertDatabaseMissing('pending_registrations', ['id' => $pending->id]);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'user_reject',
            'user_id' => $admin->id,
            'detail' => 'pending='.$pending->id,
        ]);
    }

    public function test_a_nurse_cannot_reject_a_pending_registration(): void
    {
        $nurse = User::factory()->create(['position' => 4]);
        $pending = $this->pending();

        $this->actingAs($nurse)
            ->delete('/admin/users/pending/'.$pending->id)
            ->assertForbidden();

        $this->assertDatabaseHas('pending_registrations', ['id' => $pending->id]);
    }

    // ---------------------------------------------------------------- activate / deactivate

    public function test_set_active_activates_a_user_and_audits(): void
    {
        $admin = $this->admin();
        $user = User::factory()->inactive()->create();

        $this->actingAs($admin)
            ->patch('/admin/users/'.$user->id.'/active', ['active' => true])
            ->assertRedirect();

        $this->assertTrue((bool) $user->fresh()->active);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'user_activate',
            'user_id' => $admin->id,
            'detail' => 'user='.$user->id,
        ]);
    }

    public function test_set_active_deactivates_a_user_and_audits(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->patch('/admin/users/'.$user->id.'/active', ['active' => false])
            ->assertRedirect();

        $this->assertFalse((bool) $user->fresh()->active);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'user_deactivate',
            'user_id' => $admin->id,
            'detail' => 'user='.$user->id,
        ]);
    }

    public function test_the_last_active_administrator_cannot_be_deactivated(): void
    {
        $admin = $this->admin(); // the ONLY active administrator

        $this->actingAs($admin)
            ->patchJson('/admin/users/'.$admin->id.'/active', ['active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('active');

        $this->assertTrue((bool) $admin->fresh()->active);
    }

    public function test_an_administrator_can_be_deactivated_when_another_active_admin_remains(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $this->actingAs($admin)
            ->patch('/admin/users/'.$other->id.'/active', ['active' => false])
            ->assertRedirect();

        $this->assertFalse((bool) $other->fresh()->active);
    }

    public function test_an_inactive_or_non_admin_account_does_not_count_as_a_remaining_admin(): void
    {
        $admin = $this->admin();
        // Neither an inactive admin nor an active nurse keeps the door open.
        User::factory()->inactive()->create(['position' => 0]);
        User::factory()->create(['position' => 4]);

        $this->actingAs($admin)
            ->patchJson('/admin/users/'.$admin->id.'/active', ['active' => false])
            ->assertStatus(422);

        $this->assertTrue((bool) $admin->fresh()->active);
    }

    public function test_a_nurse_cannot_toggle_activation(): void
    {
        $nurse = User::factory()->create(['position' => 4]);
        $victim = User::factory()->create();

        $this->actingAs($nurse)
            ->patch('/admin/users/'.$victim->id.'/active', ['active' => false])
            ->assertForbidden();

        $this->assertTrue((bool) $victim->fresh()->active);
    }

    // ---------------------------------------------------------------- role change

    public function test_set_position_changes_the_role_flushes_the_cap_cache_and_audits(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['position' => 4]); // Nurse

        // Warm the resolver cache with the nurse capability set.
        $this->assertFalse(AccessControl::allows($user, 'users.manage'));

        $this->actingAs($admin)
            ->patch('/admin/users/'.$user->id.'/position', ['position' => 0])
            ->assertRedirect();

        $fresh = $user->fresh();
        $this->assertSame(0, $fresh->position);
        // The per-user cache was flushed — the new (admin) capability set is visible at once.
        $this->assertTrue(AccessControl::allows($fresh, 'users.manage'));

        $this->assertDatabaseHas('audit_log', [
            'action' => 'user_role_change',
            'user_id' => $admin->id,
            'detail' => 'user='.$user->id.';position=0',
        ]);
    }

    public function test_set_position_rejects_an_out_of_range_position(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($admin)
            ->patchJson('/admin/users/'.$user->id.'/position', ['position' => 7])
            ->assertStatus(422)
            ->assertJsonValidationErrors('position');

        $this->assertSame(4, $user->fresh()->position);
    }

    public function test_the_last_active_administrator_cannot_be_demoted(): void
    {
        $admin = $this->admin(); // the ONLY active administrator

        $this->actingAs($admin)
            ->patchJson('/admin/users/'.$admin->id.'/position', ['position' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors('position');

        $this->assertSame(0, $admin->fresh()->position);
    }

    public function test_an_administrator_can_be_demoted_when_another_active_admin_remains(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $this->actingAs($admin)
            ->patch('/admin/users/'.$other->id.'/position', ['position' => 3])
            ->assertRedirect();

        $this->assertSame(3, $other->fresh()->position);
    }

    public function test_a_nurse_cannot_change_roles(): void
    {
        $nurse = User::factory()->create(['position' => 4]);
        $victim = User::factory()->create(['position' => 4]);

        $this->actingAs($nurse)
            ->patch('/admin/users/'.$victim->id.'/position', ['position' => 0])
            ->assertForbidden();

        $this->assertSame(4, $victim->fresh()->position);
    }

    // ---------------------------------------------------------------- profile edit (H / GAP 14)

    public function test_admin_profile_edit_email_uniqueness_is_case_insensitive(): void
    {
        // Same gap as ProfileTest's own case: production MySQL's collation catches a
        // differently-cased duplicate, but the raw submitted value must be normalized before the
        // uniqueness check runs for that to be true everywhere, including here.
        $admin = $this->admin();
        $user = User::factory()->create(['position' => 4]);
        User::factory()->create(['member_email' => 'taken3@example.com']);

        $this->actingAs($admin)
            ->patchJson('/admin/users/'.$user->id.'/profile', [
                'full_name' => 'Whatever',
                'member_name' => $user->member_name,
                'member_email' => 'TAKEN3@EXAMPLE.COM',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('member_email');
    }

    public function test_the_user_list_shows_when_each_account_last_signed_in(): void
    {
        // The leaver control, made checkable. The chosen process (docs/PDPL-PACK.md) is a
        // periodic review of active accounts, and that review is only as good as this
        // column — without it an account belonging to someone who left six months ago looks
        // exactly like one in daily use. `last_login_at` had been recorded since July and
        // read by nothing at all.
        $stale = User::factory()->create(['position' => 4, 'last_login_at' => now()->subDays(200)]);
        $never = User::factory()->create(['position' => 4, 'last_login_at' => null]);

        $this->actingAs($this->admin())->get('/admin/users')
            ->assertInertia(fn (Assert $page) => $page
                ->where('users', function ($users) use ($stale, $never) {
                    $rows = collect($users)->keyBy('id');

                    return $rows[$stale->id]['last_login_at'] !== null
                        && $rows[$stale->id]['dormant_days'] >= 90
                        && $rows[$never->id]['last_login_at'] === null;
                }));
    }

}
