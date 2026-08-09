<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\PersonController;
use App\Models\AuditLog;
use App\Models\Level;
use App\Models\Person;
use App\Models\PersonLevel;
use App\Models\User;
use App\Support\LevelAssignment;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Munawib LV-02's bulk operations. Findings 12 and 13 shape the whole file: authorize the
 * ENTIRE selection before any mutation (never per-row as it iterates), and make the
 * last-administrator guard SET-AWARE — deactivating administrators one at a time is refused at
 * the last one; deactivating three at once must be refused too, not slip through because each
 * looked survivable alone (`PositionChange::wouldLeaveNoActiveAdministrator()`).
 *
 * Bulk "resend invitations" is P1c-2 (an ACCOUNT action needing AC-02's endpoint) — not tested
 * here, and the screen names it disabled rather than shipping a dead button.
 */
class PeopleBulkTest extends TestCase
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

    /**
     * The FormRequest validates the WHOLE `ids` array as one unit — Laravel resolves and
     * validates a FormRequest before the controller method ever runs, so an id that fails
     * `Rule::exists` refuses the ENTIRE submission with NOTHING written for the valid ones. This
     * is the `InvitationController::store()` ordering finding 12 names (authorize the whole set
     * before any mutation), realised here without a second copy of the mechanism — see
     * `PersonController::bulk()`'s own docblock. The unknown id is placed LAST, matching the
     * plan's own framing ("the last person is unauthorized").
     */
    public function test_the_whole_selection_is_authorized_before_any_write(): void
    {
        $a = Person::factory()->create(['active' => true]);
        $b = Person::factory()->create(['active' => true]);

        $this->actingAs($this->admin)->post('/admin/people/bulk', [
            'action' => 'set_active',
            'active' => false,
            'ids' => [$a->id, $b->id, 999999],
        ])->assertSessionHasErrors('ids.2');

        $this->assertTrue($a->fresh()->active);
        $this->assertTrue($b->fresh()->active);
    }

    /** finding 13, the SET-AWARE case: each of the last two admins passes a PER-ROW check. */
    public function test_deactivating_every_remaining_administrator_is_refused_as_a_set(): void
    {
        $second = User::factory()->create(['position' => 0, 'full_name' => 'BBB Admin']);

        $this->actingAs($this->admin)->post('/admin/people/bulk', [
            'action' => 'set_active',
            'active' => false,
            'ids' => [$this->admin->person->id, $second->person->id],
        ])->assertSessionHasErrors('ids');

        $this->assertTrue($this->admin->fresh()->active);
        $this->assertTrue($second->fresh()->active);
        $this->assertTrue($this->admin->person->fresh()->active);
        $this->assertTrue($second->person->fresh()->active);
    }

    public function test_deactivating_all_but_one_administrator_is_allowed(): void
    {
        $second = User::factory()->create(['position' => 0, 'full_name' => 'BBB Admin']);

        $this->actingAs($this->admin)->post('/admin/people/bulk', [
            'action' => 'set_active',
            'active' => false,
            'ids' => [$second->person->id],
        ])->assertRedirect();

        $this->assertTrue($this->admin->fresh()->active);
        $this->assertFalse($second->fresh()->active);
        $this->assertFalse($second->person->fresh()->active);
    }

    /** Every affected person's history goes through the one writer — provenance columns prove it. */
    public function test_bulk_set_level_uses_the_one_writer(): void
    {
        $level = Level::factory()->create(['code' => 'BULK1']);
        $a = Person::factory()->create();
        $b = Person::factory()->create();

        $this->actingAs($this->admin)->post('/admin/people/bulk', [
            'action' => 'set_level',
            'level_id' => $level->id,
            'ids' => [$a->id, $b->id],
        ])->assertRedirect();

        foreach ([$a, $b] as $person) {
            $span = PersonLevel::where('person_id', $person->id)->where('level_id', $level->id)->firstOrFail();
            $this->assertSame((int) $this->admin->id, $span->created_by);
        }
    }

    public function test_bulk_set_level_reports_per_person_outcomes(): void
    {
        $level = Level::factory()->create(['code' => 'BULK2']);
        $already = Person::factory()->create();
        LevelAssignment::assign($already, $level, '2026-01-01');
        $fresh = Person::factory()->create();

        $this->actingAs($this->admin)->post('/admin/people/bulk', [
            'action' => 'set_level',
            'level_id' => $level->id,
            'ids' => [$already->id, $fresh->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'person_bulk_item',
            'detail' => 'person='.$already->id.';outcome=skipped_same_level',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'person_bulk_item',
            'detail' => 'person='.$fresh->id.';outcome=assigned',
        ]);
    }

    public function test_an_unknown_person_id_in_the_selection_is_a_422_not_a_silent_skip(): void
    {
        $a = Person::factory()->create(['active' => true]);

        $this->actingAs($this->admin)->post('/admin/people/bulk', [
            'action' => 'set_active',
            'active' => false,
            'ids' => [$a->id, 999999],
        ])->assertSessionHasErrors('ids.1');

        // Not a silent skip: the valid id was not applied either.
        $this->assertTrue($a->fresh()->active);
    }

    public function test_a_duplicate_id_in_the_selection_is_collapsed_not_applied_twice(): void
    {
        $level = Level::factory()->create(['code' => 'BULK3']);
        $person = Person::factory()->create();

        $this->actingAs($this->admin)->post('/admin/people/bulk', [
            'action' => 'set_level',
            'level_id' => $level->id,
            'ids' => [$person->id, $person->id],
        ])->assertRedirect();

        $this->assertSame(1, PersonLevel::where('person_id', $person->id)->where('level_id', $level->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'person_bulk_item')->where('detail', 'person='.$person->id.';outcome=assigned')->count());
    }

    /** A payload only a hospital roster export would legitimately carry — the system must not refuse it, only neutralise it. */
    public function test_the_export_is_neutralised(): void
    {
        $person = Person::factory()->create(['full_name' => "=cmd|'/c calc'!A1"]);

        $response = $this->actingAs($this->admin)->post('/admin/people/bulk', [
            'action' => 'export',
            'ids' => [$person->id],
        ]);

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString("'=cmd|'/c calc'!A1", $content);
        $this->assertStringNotContainsString("\n=cmd", $content);
    }

    /**
     * `PersonController::exportTable()` is the pure column-shaping step of the export — it is
     * tested directly rather than through the HTTP route because `PersonPolicy::viewContact()`'s
     * first branch (holding `people.manage`) is always true for whoever can reach
     * `/admin/people/bulk` at all (the route itself requires that capability), so a
     * phone-suppressed export can never be OBSERVED end-to-end through this endpoint — the same
     * structural note Task 2's `ContactFieldsAreProjectedOnceTest` amendment records for the
     * People screen itself. `ContactVisibilityTest` already proves the VALUE is withheld; this
     * proves the COLUMN follows: a viewer whose projected rows carry no `phone` key gets a file
     * with no Phone header at all, never a column of blanks.
     */
    public function test_the_export_respects_contact_visibility(): void
    {
        $positions = \App\Models\Position::orderBy('id')->get(['id', 'name'])->keyBy('id');

        $withoutPhone = new Collection([
            ['full_name' => 'No Phone Person', 'short_name' => null, 'position' => 4, 'level' => null, 'active' => true, 'has_account' => false],
        ]);

        ['headers' => $headers] = PersonController::exportTable($withoutPhone, $positions);
        $this->assertNotContains('Phone', $headers);

        $withPhone = new Collection([
            ['full_name' => 'Has Phone Person', 'short_name' => null, 'position' => 4, 'level' => null, 'active' => true, 'has_account' => false, 'phone' => '0500000000'],
        ]);

        ['headers' => $headersWithPhone, 'rows' => $rows] = PersonController::exportTable($withPhone, $positions);
        $this->assertContains('Phone', $headersWithPhone);
        $this->assertSame('0500000000', $rows->first()[count($headersWithPhone) - 1]);
    }

    public function test_bulk_writes_are_audited_as_one_summary_plus_one_row_per_person(): void
    {
        $level = Level::factory()->create(['code' => 'BULK4']);
        $a = Person::factory()->create();
        $b = Person::factory()->create();

        $this->actingAs($this->admin)->post('/admin/people/bulk', [
            'action' => 'set_level',
            'level_id' => $level->id,
            'ids' => [$a->id, $b->id],
        ])->assertRedirect();

        $this->assertSame(1, AuditLog::where('action', 'person_bulk')->where('detail', 'action=set_level;n=2')->count());
        $this->assertSame(2, AuditLog::where('action', 'person_bulk_item')->count());
    }

    public function test_a_resident_is_refused(): void
    {
        $resident = User::factory()->create(['position' => 4]);
        $person = Person::factory()->create();

        $this->actingAs($resident)->post('/admin/people/bulk', [
            'action' => 'set_active',
            'active' => false,
            'ids' => [$person->id],
        ])->assertForbidden();
    }
}
