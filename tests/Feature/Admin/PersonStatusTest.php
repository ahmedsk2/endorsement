<?php

namespace Tests\Feature\Admin;

use App\Models\Level;
use App\Models\Person;
use App\Models\User;
use App\Support\LevelAssignment;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review finding 4, the most serious of the batch: "deactivate a person" had THREE writers of
 * `people.active` and only `PersonController::applySetActive()` (LV-02's bulk tool) kept the
 * linked account's `users.active` in step. `PersonController::update()` (the ordinary People edit
 * form) wrote `active` straight through `$person->update($data)`, and `Promotion::commit()`'s
 * retire path wrote `$person->update(['active' => false])` directly — neither touched the
 * account, so a resident retired through either surface stopped being NAMED on sheets but kept
 * the ability to log in and read handover sheets carrying patient PHI.
 *
 * `App\Support\PersonStatus::apply()` is now the one definition, in PositionChange's shape,
 * called from all three sites. It also carries PositionChange's last-administrator guard
 * (`UserManagementController::setActive()` already established this exact guard from the account
 * side) — deactivating the sole active Administrator through the People screen must be refused
 * the same way demoting them is.
 */
class PersonStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
        $this->admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Some Body',
            'short_name' => null,
            'position' => 4,
            'email' => null,
            'phone' => null,
            'joined_at' => null,
            'notes' => null,
            'constraints' => null,
            'external' => false,
            'active' => true,
        ], $overrides);
    }

    /** The bug, proven end to end: deactivate through the People EDIT screen, then try to log in. */
    public function test_deactivating_a_person_through_the_people_edit_screen_blocks_their_login(): void
    {
        $account = User::factory()->create(['member_name' => 'grounded', 'position' => 4]);

        $this->post('/login', ['member_name' => 'grounded', 'password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($account->fresh());
        $this->post('/logout');

        $this->actingAs($this->admin)
            ->patch('/admin/people/'.$account->person_id, $this->payload(['active' => false]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertFalse($account->fresh()->active, 'the account must be deactivated too');

        // `actingAs()` forces the admin authenticated for every subsequent call in this test
        // regardless of session state — log out for real before checking the TARGET is refused,
        // or a failed login attempt below would leave the admin looking "still logged in" and
        // assertGuest() would fail for a reason that has nothing to do with this fix.
        $this->post('/logout');

        $this->post('/login', ['member_name' => 'grounded', 'password' => 'password'])
            ->assertSessionHasErrors('member_name');
        $this->assertGuest();
    }

    /** The same bug, on the OTHER broken surface: LV-03's "retire this cohort" action. */
    public function test_retiring_a_person_through_the_promotion_screen_blocks_their_login(): void
    {
        $r4 = Level::where('code', 'R4')->firstOrFail();
        $account = User::factory()->create(['member_name' => 'retiree', 'position' => 4]);
        LevelAssignment::assign($account->person, $r4, '2026-01-01');

        $this->post('/login', ['member_name' => 'retiree', 'password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->post('/logout');

        $this->actingAs($this->admin)->post('/admin/promotion/commit', [
            'action' => 'retire',
            'from_level_id' => $r4->id,
            'effective_from' => '2026-07-01',
            'ids' => [$account->person_id],
        ])->assertRedirect();

        $this->assertFalse($account->person->fresh()->active);
        $this->assertFalse($account->fresh()->active, 'the account must be deactivated too');

        $this->post('/logout');

        $this->post('/login', ['member_name' => 'retiree', 'password' => 'password'])
            ->assertSessionHasErrors('member_name');
        $this->assertGuest();
    }

    /** The reference-correct surface (LV-02 bulk) must keep working exactly as before. */
    public function test_bulk_deactivation_still_blocks_login_too(): void
    {
        $account = User::factory()->create(['member_name' => 'bulked', 'position' => 4]);

        $this->actingAs($this->admin)->post('/admin/people/bulk', [
            'action' => 'set_active',
            'active' => false,
            'ids' => [$account->person_id],
        ])->assertRedirect();

        $this->assertFalse($account->fresh()->active);

        $this->post('/logout');

        $this->post('/login', ['member_name' => 'bulked', 'password' => 'password'])
            ->assertSessionHasErrors('member_name');
        $this->assertGuest();
    }

    /** Symmetric with `PositionChange::isLastActiveAdministrator()` and the account console's own guard. */
    public function test_the_last_active_administrator_cannot_be_deactivated_through_the_people_screen(): void
    {
        $this->actingAs($this->admin)
            ->patch('/admin/people/'.$this->admin->person_id, $this->payload(['position' => 0, 'active' => false]))
            ->assertSessionHasErrors('active');

        $this->assertTrue($this->admin->fresh()->active);
        $this->assertTrue($this->admin->person->fresh()->active);
    }

    /** Once another Administrator exists, the same request succeeds. */
    public function test_the_last_active_administrator_can_be_deactivated_once_another_exists(): void
    {
        User::factory()->create(['position' => 0]);

        $this->actingAs($this->admin)
            ->patch('/admin/people/'.$this->admin->person_id, $this->payload(['position' => 0, 'active' => false]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertFalse($this->admin->fresh()->active);
    }

    /** A roster-only person (no account) has nothing to flush — the write must still succeed. */
    public function test_deactivating_a_roster_only_person_has_no_account_to_touch(): void
    {
        $person = Person::factory()->create(['active' => true]);

        $this->actingAs($this->admin)
            ->patch('/admin/people/'.$person->id, $this->payload(['active' => false]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertFalse($person->fresh()->active);
    }
}
