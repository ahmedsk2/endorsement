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
     * Two writers of `people.position` is two chances to forget the cache flush or the lockout
     * guard. This asserts there is one DEFINITION, not two that happen to agree today.
     *
     * IT USED TO NAME `PositionChange::isLastActiveAdministrator` AS THE SHARED GUARD, and ruling
     * 45 deleted that method: it asked about the Administrator ROLE, which stopped implying
     * "somebody holds `access.manage`" once the capability became deniable per account, and five
     * doors guarding on it were measured emptying the capability with a 302. The account console
     * now calls `App\Support\AccessManageGuard::guarding()`, which is the shared definition.
     *
     * THE NEEDLES ARE CODE SHAPES, NOT WORDS, and that matters more than it did before. When this
     * file was first written the two happened to coincide; while the fix was being made, the
     * controller's PROSE mentioned the deleted method (explaining what replaced it) and the old
     * `assertStringContainsString('PositionChange::isLastActiveAdministrator', ...)` went on
     * passing against a method that no longer existed — a guard satisfied by a comment. So the
     * DECLARATION bans below are matched against source with its comments stripped, and the
     * positive assertions name calls that must really be there.
     */
    public function test_the_account_console_delegates_to_the_one_definition(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/Admin/UserManagementController.php'));
        $code = $this->withoutComments($source);

        $this->assertStringContainsString('PositionChange::apply', $code,
            'the account console no longer routes a position change through the one writer');
        $this->assertStringContainsString('AccessManageGuard::guarding', $code,
            'the account console no longer routes its deactivation through the one lockout guard');

        // Neither predicate may come back, here or anywhere: a role-shaped answer to the
        // capability question is what ruling 45 removed.
        foreach ([
            app_path('Http/Controllers/Admin/UserManagementController.php'),
            app_path('Http/Controllers/Admin/PersonController.php'),
            app_path('Support/PositionChange.php'),
            app_path('Support/PersonStatus.php'),
            app_path('Support/AccountUnbind.php'),
        ] as $path) {
            $body = $this->withoutComments((string) file_get_contents($path));

            $this->assertStringNotContainsString('isLastActiveAdministrator', $body, basename($path));
            $this->assertStringNotContainsString('wouldLeaveNoActiveAdministrator', $body, basename($path));
        }
    }

    /**
     * Source with comments and docblocks removed, so a source-level assertion cannot be satisfied
     * by prose about the thing it is looking for. Same technique `RotaAccessTest`'s narrow scan
     * uses, and for the same reason.
     */
    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
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
