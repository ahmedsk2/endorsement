<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\PendingRegistration;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The CHIEF RESIDENT role (position 5): registers as a Resident, is promoted by an
 * Administrator, and then holds ONE narrow admin power — activating/deactivating and
 * approving RESIDENT accounts (`users.manage_residents`). Nothing else: no role changes,
 * no profile edits, no non-resident accounts. The Nurse role (position 1) is retired
 * from this system entirely.
 */
class ChiefResidentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['position' => 0]);
    }

    private function chief(): User
    {
        return User::factory()->create(['position' => 5]);
    }

    // ------------------------------------------------------------ role catalog

    public function test_the_nurse_role_is_retired_and_chief_resident_exists(): void
    {
        $this->assertDatabaseMissing('positions', ['id' => 1]);
        $this->assertDatabaseHas('positions', ['id' => 5, 'name' => 'Chief Resident']);
    }

    public function test_registration_offers_neither_nurse_nor_chief(): void
    {
        $this->get('/register')->assertInertia(fn (Assert $page) => $page
            ->where('positions', fn ($positions) => ! collect($positions)->keys()->contains(fn ($k) => in_array((int) $k, [0, 1, 5], true)))
        );

        foreach ([1, 5] as $position) {
            $this->post('/register', [
                'full_name' => 'X',
                'member_name' => 'x_'.$position,
                'member_email' => 'x'.$position.'@example.org',
                'position' => $position,
                'password' => 'Secret-pass1',
                'password_confirmation' => 'Secret-pass1',
            ])->assertSessionHasErrors('position');
        }
    }

    public function test_an_administrator_promotes_a_resident_to_chief(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($this->admin())
            ->patch('/admin/users/'.$resident->id.'/position', ['position' => 5])
            ->assertRedirect();

        $this->assertSame(5, $resident->fresh()->position);
    }

    // ------------------------------------------------------------ scoped powers

    public function test_a_chief_sees_a_residents_only_users_page(): void
    {
        $resident = User::factory()->create(['position' => 4]);
        $consultant = User::factory()->create(['position' => 3]);
        PendingRegistration::create(['member_name' => 'pend_res', 'password' => 'x', 'position' => 4]);
        PendingRegistration::create(['member_name' => 'pend_cons', 'password' => 'x', 'position' => 3]);

        $this->actingAs($this->chief())
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($resident, $consultant) {
                $users = collect($page->toArray()['props']['users']);
                $this->assertTrue($users->pluck('id')->contains($resident->id));
                $this->assertFalse($users->pluck('id')->contains($consultant->id));

                $pending = collect($page->toArray()['props']['pending']);
                $this->assertTrue($pending->pluck('member_name')->contains('pend_res'));
                $this->assertFalse($pending->pluck('member_name')->contains('pend_cons'));

                // The page knows the viewer holds only the scoped power.
                $this->assertFalse($page->toArray()['props']['canManageAll']);
            });
    }

    public function test_a_chief_can_deactivate_and_reactivate_a_resident(): void
    {
        $resident = User::factory()->create(['position' => 4, 'active' => true]);
        $chief = $this->chief();

        $this->actingAs($chief)
            ->patch('/admin/users/'.$resident->id.'/active', ['active' => false])
            ->assertRedirect();
        $this->assertFalse($resident->fresh()->active);

        $this->actingAs($chief)
            ->patch('/admin/users/'.$resident->id.'/active', ['active' => true])
            ->assertRedirect();
        $this->assertTrue($resident->fresh()->active);

        $this->assertNotNull(AuditLog::where('action', 'user_deactivate')->where('user_id', $chief->id)->first());
        $this->assertNotNull(AuditLog::where('action', 'user_activate')->where('user_id', $chief->id)->first());
    }

    public function test_a_chief_cannot_touch_non_resident_accounts(): void
    {
        $chief = $this->chief();

        foreach ([0, 2, 3, 5] as $position) {
            $target = User::factory()->create(['position' => $position, 'active' => true]);

            $this->actingAs($chief)
                ->patch('/admin/users/'.$target->id.'/active', ['active' => false])
                ->assertForbidden();
            $this->assertTrue($target->fresh()->active);
        }
    }

    public function test_a_chief_can_approve_a_resident_registration_only(): void
    {
        $chief = $this->chief();

        $resPending = PendingRegistration::create([
            'member_name' => 'new_res', 'member_email' => 'nr@example.org',
            'full_name' => 'New Res', 'password' => 'hashed-placeholder', 'position' => 4,
        ]);
        $consPending = PendingRegistration::create([
            'member_name' => 'new_cons', 'member_email' => 'nc@example.org',
            'full_name' => 'New Cons', 'password' => 'hashed-placeholder', 'position' => 3,
        ]);

        $this->actingAs($chief)->post('/admin/users/pending/'.$resPending->id.'/approve')->assertRedirect();
        $this->assertDatabaseHas('users', ['member_name' => 'new_res', 'position' => 4]);

        $this->actingAs($chief)->post('/admin/users/pending/'.$consPending->id.'/approve')->assertForbidden();
        $this->assertDatabaseMissing('users', ['member_name' => 'new_cons']);
    }

    public function test_a_chief_cannot_change_roles_or_profiles(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($this->chief())
            ->patch('/admin/users/'.$resident->id.'/position', ['position' => 3])
            ->assertForbidden();

        $this->actingAs($this->chief())
            ->patch('/admin/users/'.$resident->id.'/profile', ['full_name' => 'Renamed'])
            ->assertForbidden();
    }

    public function test_a_plain_resident_has_no_users_page(): void
    {
        $this->actingAs(User::factory()->create(['position' => 4]))
            ->get('/admin/users')
            ->assertForbidden();
    }

    // ------------------------------------------------------------ clinical parity

    public function test_a_chief_remains_a_resident_clinically(): void
    {
        $chief = User::factory()->create(['position' => 5, 'full_name' => 'Dr Chief']);
        $editor = User::factory()->create(['position' => 2]);

        // Still in the Endorsed by/to pickers (a chief IS a resident who hands over)...
        $this->actingAs($editor)
            ->get('/endorsement/PICU/2026-07-10')
            ->assertInertia(function (Assert $page) use ($chief) {
                $ids = collect($page->toArray()['props']['staff']['endorsers'])->pluck('id');
                $this->assertTrue($ids->contains($chief->id));
            });

        // ...and can edit the sheet like any resident.
        $this->actingAs($chief)
            ->post('/endorsement/PICU/2026-07-10/rows', ['mrn' => 'CH-1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }
}
