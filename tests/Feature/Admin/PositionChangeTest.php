<?php

namespace Tests\Feature\Admin;

use App\Models\Person;
use App\Models\User;
use App\Support\AccessControl;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decision C: one definition of "change a person's job position". Finding 6 is two bugs wearing
 * one coat — a stale capability cache (`AccessControl::resolve()` keys off `people.position`
 * through a read-through accessor, cached 600s) and a bypassed last-admin guard. A People screen
 * written in the house style would have shipped both. `App\Support\PositionChange` is the one
 * writer both `UserManagementController::setPosition()` and `PersonController` now call through.
 */
class PositionChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    public function test_changing_a_position_through_the_people_screen_busts_the_capability_cache(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        $other = User::factory()->create(['position' => 0]);

        // Warm the resolver cache for the target.
        $this->assertContains('access.manage', AccessControl::capabilitiesFor($other));

        $this->actingAs($admin)
            ->patch('/admin/people/'.$other->person_id, $this->payload(['position' => 4]))
            ->assertRedirect();

        // No manual flush in this test — the write itself must have busted the cache.
        $this->assertNotContains('access.manage', AccessControl::capabilitiesFor($other->fresh()));
    }

    public function test_the_last_active_administrator_cannot_be_demoted_through_the_people_screen(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);

        $this->actingAs($admin)
            ->patch('/admin/people/'.$admin->person_id, $this->payload(['position' => 4]))
            ->assertSessionHasErrors('position');

        $this->assertSame(0, $admin->person->fresh()->position);
    }

    public function test_the_last_active_administrator_can_still_be_demoted_once_another_exists(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        User::factory()->create(['position' => 0]);

        $this->actingAs($admin)
            ->patch('/admin/people/'.$admin->person_id, $this->payload(['position' => 4]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(4, $admin->person->fresh()->position);
    }

    public function test_a_roster_only_persons_position_changes_with_no_account_to_flush(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        $person = Person::factory()->create(['position' => 3]);

        $this->actingAs($admin)
            ->patch('/admin/people/'.$person->id, $this->payload(['position' => 4]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(4, $person->fresh()->position);
    }

    /**
     * Two writers of `people.position` is two chances to forget the cache flush or the last-admin
     * guard. This asserts there is one DEFINITION, not two that happen to agree today.
     *
     * Not a blanket `assertStringNotContainsString('isLastActiveAdministrator', $source)`: the
     * refactored `setActive()` still legitimately CALLS `PositionChange::isLastActiveAdministrator(
     * $user)` — the shared, single definition — and that qualified call still contains the bare
     * word "isLastActiveAdministrator" as a substring. A blanket ban would therefore fail against
     * the exact code this task's own plan text asks for. What must be absent is a SECOND
     * DECLARATION of the method (`function isLastActiveAdministrator(`), which is what would make
     * this two definitions that happen to agree today rather than one.
     */
    public function test_the_account_console_delegates_to_the_one_definition(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Admin/UserManagementController.php'));

        $this->assertStringContainsString('PositionChange::apply', $source);
        $this->assertStringContainsString('PositionChange::isLastActiveAdministrator', $source);
        $this->assertStringNotContainsString('function isLastActiveAdministrator(', $source);
    }

    public function test_the_audit_row_names_ids_only(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        $person = Person::factory()->create(['position' => 3]);

        $this->actingAs($admin)->patch('/admin/people/'.$person->id, $this->payload(['position' => 4]));

        $row = \App\Models\AuditLog::where('action', 'user_role_change')
            ->where('detail', 'like', 'person='.$person->id.';%')
            ->firstOrFail();

        $this->assertMatchesRegularExpression('/^person=\d+;user=(\d+|none);position=\d+$/', $row->detail);
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
}
