<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Level;
use App\Models\Person;
use App\Models\PersonLevel;
use App\Models\User;
use App\Support\LevelAssignment;
use App\Support\Promotion;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Munawib LV-03's annual promotion. P1b Owner Decision A / P1c Decision D, restated as tests: no
 * terminal level and none is inferred (`LevelLadderTest` pins the absence), the operator names
 * BOTH ends explicitly, `EXT` is offered as neither source nor target, and the retire path is a
 * separate action rather than a target value that means "out".
 */
class PromotionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
        $this->admin = User::factory()->create(['position' => 0]);
    }

    protected function tearDown(): void
    {
        // One test registers a temporary PersonLevel::saving() listener to force a
        // mid-transaction failure (the same technique UnitMergeTest uses); this class has no
        // listeners of its own (grepped before writing this file), so flushing after every test
        // is a complete, safe cleanup.
        PersonLevel::flushEventListeners();
        parent::tearDown();
    }

    private function level(string $code): Level
    {
        return Level::where('code', $code)->firstOrFail();
    }

    // --- The decision, pinned -------------------------------------------------------------

    public function test_the_screen_offers_no_computed_target(): void
    {
        $this->actingAs($this->admin)->get('/admin/promotion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Promotion')
                ->has('levels', 4)
                ->missing('suggested_target')
                ->missing('next_level')
                ->missing('nextLevel')
            );
    }

    public function test_ext_is_offered_as_neither_source_nor_target(): void
    {
        $this->actingAs($this->admin)->get('/admin/promotion')
            ->assertInertia(fn (Assert $page) => $page
                ->has('levels', 4)
                ->where('levels.0.code', 'R1')
                ->where('levels.1.code', 'R2')
                ->where('levels.2.code', 'R3')
                ->where('levels.3.code', 'R4')
            );
    }

    public function test_promoting_into_an_external_level_is_refused_server_side(): void
    {
        $ext = $this->level('EXT');
        $r1 = $this->level('R1');
        $person = Person::factory()->create();
        LevelAssignment::assign($person, $r1, '2026-01-01');

        $this->actingAs($this->admin)->post('/admin/promotion/commit', [
            'action' => 'promote',
            'from_level_id' => $r1->id,
            'to_level_id' => $ext->id,
            'effective_from' => '2026-07-01',
            'ids' => [$person->id],
        ])->assertSessionHasErrors('to_level_id');

        $this->assertDatabaseMissing('person_levels', ['person_id' => $person->id, 'level_id' => $ext->id]);
    }

    public function test_the_same_level_on_both_ends_is_refused_as_a_no_op(): void
    {
        $r2 = $this->level('R2');
        $person = Person::factory()->create();
        LevelAssignment::assign($person, $r2, '2026-01-01');

        $this->actingAs($this->admin)->post('/admin/promotion/preview', [
            'from_level_id' => $r2->id,
            'to_level_id' => $r2->id,
            'effective_from' => '2026-07-01',
        ])->assertSessionHasErrors('to_level_id');

        $this->actingAs($this->admin)->post('/admin/promotion/commit', [
            'action' => 'promote',
            'from_level_id' => $r2->id,
            'to_level_id' => $r2->id,
            'effective_from' => '2026-07-01',
            'ids' => [$person->id],
        ])->assertSessionHasErrors('to_level_id');

        $this->assertSame(1, PersonLevel::where('person_id', $person->id)->count());
    }

    /** R4 -> R1 previews and commits: the system does not know that is wrong and must not pretend to. */
    public function test_a_backwards_promotion_is_allowed_and_says_so_in_the_preview(): void
    {
        $r4 = $this->level('R4');
        $r1 = $this->level('R1');
        $person = Person::factory()->create();
        LevelAssignment::assign($person, $r4, '2026-01-01');

        $this->actingAs($this->admin)->post('/admin/promotion/preview', [
            'from_level_id' => $r4->id,
            'to_level_id' => $r1->id,
            'effective_from' => '2026-07-01',
        ])->assertSessionHas('promotion_preview', fn (array $p): bool => $p['is_backwards'] === true);

        $this->actingAs($this->admin)->post('/admin/promotion/commit', [
            'action' => 'promote',
            'from_level_id' => $r4->id,
            'to_level_id' => $r1->id,
            'effective_from' => '2026-07-01',
            'ids' => [$person->id],
        ])->assertRedirect();

        $this->assertTrue($person->fresh()->levelAt('2026-07-01')->is($r1));
    }

    // --- The mechanics ----------------------------------------------------------------------

    public function test_the_preview_lists_the_cohort_with_no_write(): void
    {
        $r1 = $this->level('R1');
        $r2 = $this->level('R2');
        $person = Person::factory()->create();
        LevelAssignment::assign($person, $r1, '2026-01-01');

        $before = PersonLevel::count();

        $this->actingAs($this->admin)->post('/admin/promotion/preview', [
            'from_level_id' => $r1->id,
            'to_level_id' => $r2->id,
            'effective_from' => '2026-07-01',
        ])->assertSessionHas('promotion_preview', fn (array $p): bool => count($p['cohort']) === 1
            && $p['cohort'][0]['person_id'] === $person->id
            && $p['cohort'][0]['outcome'] === LevelAssignment::ASSIGNED);

        $this->assertSame($before, PersonLevel::count());
    }

    /**
     * NOTE ON "already at the target": the plan's own Step 1 prose names this as one of three
     * skip reasons to cover, but it is UNREACHABLE through a real preview call. `cohort($from,
     * $on)` only ever returns people whose level AT `$on` resolves to `$from` — and
     * `predictOutcome()`'s SKIPPED_SAME_LEVEL branch fires exactly when that resolved level
     * already equals the TARGET. Since the controller separately refuses `$from === $to` as a
     * no-op (its own test above), a person genuinely IN the `$from` cohort can never
     * simultaneously already BE at a DIFFERENT `$to` — the two conditions are mutually exclusive
     * by construction, not merely untested. Substituted the reachable sibling instead: an EXACT
     * DATE COLLISION (`SKIPPED_EXISTING`) — a person who already has a re-affirming span
     * recorded starting exactly on the promotion date is skipped regardless of level, because
     * the writer never upserts.
     */
    public function test_the_preview_names_who_will_be_skipped_and_why(): void
    {
        $r1 = $this->level('R1');
        $r2 = $this->level('R2');

        // An exact-date collision: a span already starts ON the promotion date. Seeded directly
        // via the factory (not LevelAssignment::assign(), which would itself refuse to write a
        // second row here — a re-affirmation of the SAME level is exactly SKIPPED_SAME_LEVEL,
        // not the exact-date collision this sub-case needs to exercise). Fixture-seeding
        // `person_levels` directly is the established exception LevelHistoryTest already relies
        // on — the guard's own allow-list reasoning is that a test fixture is not the production
        // integrity surface it exists to close.
        $collision = Person::factory()->create();
        PersonLevel::factory()->create([
            'person_id' => $collision->id, 'level_id' => $r1->id,
            'effective_from' => '2026-01-01', 'effective_to' => '2026-06-30',
        ]);
        PersonLevel::factory()->create([
            'person_id' => $collision->id, 'level_id' => $r1->id,
            'effective_from' => '2026-07-01', 'effective_to' => null,
        ]);

        // A later span already claims history past the promotion date.
        $laterSpan = Person::factory()->create();
        LevelAssignment::assign($laterSpan, $r1, '2026-01-01');
        LevelAssignment::assign($laterSpan, $r2, '2026-08-01');

        // Roster-active but the linked ACCOUNT is deactivated. Promotion is a roster/level
        // fact, not a login one — this is NOT a reason to skip.
        $noLogin = User::factory()->create(['position' => 4, 'active' => false]);
        LevelAssignment::assign($noLogin->person, $r1, '2026-01-01');

        $this->actingAs($this->admin)->post('/admin/promotion/preview', [
            'from_level_id' => $r1->id,
            'to_level_id' => $r2->id,
            'effective_from' => '2026-07-01',
        ])->assertSessionHas('promotion_preview', function (array $p) use ($collision, $laterSpan, $noLogin): bool {
            $byId = collect($p['cohort'])->keyBy('person_id');

            return $byId[$collision->id]['outcome'] === LevelAssignment::SKIPPED_EXISTING
                && $byId[$laterSpan->id]['outcome'] === LevelAssignment::REFUSED_OVERLAP
                && $byId[$noLogin->person->id]['outcome'] === LevelAssignment::ASSIGNED;
        });
    }

    /**
     * The whole commit is ONE transaction. A failure forced partway through (here, on the
     * SECOND person's span) must leave the FIRST person's already-applied write rolled back too.
     */
    public function test_the_commit_is_one_transaction(): void
    {
        $r1 = $this->level('R1');
        $r2 = $this->level('R2');
        $a = Person::factory()->create();
        $b = Person::factory()->create();
        LevelAssignment::assign($a, $r1, '2026-01-01');
        LevelAssignment::assign($b, $r1, '2026-01-01');

        PersonLevel::saving(function (PersonLevel $model) use ($b): void {
            if ((int) $model->person_id === $b->id) {
                throw new RuntimeException('Simulated mid-promotion failure.');
            }
        });

        try {
            Promotion::commit($r1, $r2, '2026-07-01', [$a->id, $b->id], ['actor' => $this->admin->id]);
            $this->fail('Expected the forced failure to propagate out of commit().');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated mid-promotion failure.', $e->getMessage());
        }

        $this->assertSame(1, PersonLevel::where('person_id', $a->id)->count());
        $this->assertTrue($a->fresh()->levelAt('2026-07-01')->is($r1));
    }

    /**
     * A person added to the source level AFTER the preview is not silently swept into a commit
     * that never named them; a person whose id WAS submitted but has since left the fresh cohort
     * does not fail the whole run — the same discipline UnitMerge::commit() established for
     * signoff collisions.
     */
    public function test_the_commit_re_derives_the_cohort_inside_the_transaction(): void
    {
        $r1 = $this->level('R1');
        $r2 = $this->level('R2');

        $original = Person::factory()->create();
        LevelAssignment::assign($original, $r1, '2026-01-01');

        // Joins the source level AFTER the preview would have run.
        $addedLater = Person::factory()->create();
        LevelAssignment::assign($addedLater, $r1, '2026-06-01');

        // Already moved off the source level by the time commit runs — still in the submitted
        // id list (a stale preview's selection), but no longer in the fresh cohort.
        $movedAlready = Person::factory()->create();
        LevelAssignment::assign($movedAlready, $r1, '2026-01-01');
        LevelAssignment::assign($movedAlready, $r2, '2026-02-01');

        $result = Promotion::commit($r1, $r2, '2026-07-01', [$original->id, $movedAlready->id], [
            'actor' => $this->admin->id,
        ]);

        $this->assertTrue($original->fresh()->levelAt('2026-07-01')->is($r2));
        // Not silently swept in: never named in the submitted ids, so untouched even though the
        // FRESH cohort now includes them.
        $this->assertTrue($addedLater->fresh()->levelAt('2026-07-01')->is($r1));
        // Named in the submitted ids but no longer in the fresh cohort: skipped, not a failure.
        $this->assertArrayNotHasKey($movedAlready->id, $result['outcomes']);
    }

    /**
     * Review finding 3: `person_levels.reason` is `varchar(255)` (migration
     * 2026_08_14_120002:41-42), but this controller validated `max:500` — MySQL 8.4 strict mode
     * refuses an over-length insert with an uncaught 1406 on the annual promotion; SQLite ignores
     * the column length outright and would let this pass silently, which is exactly why this
     * asserts the VALIDATION REFUSAL (a 422 naming the field) rather than anything DB-visible.
     */
    public function test_a_reason_over_the_column_length_is_refused_by_validation(): void
    {
        $r1 = $this->level('R1');
        $person = Person::factory()->create();
        LevelAssignment::assign($person, $r1, '2026-01-01');

        $this->actingAs($this->admin)->post('/admin/promotion/commit', [
            'action' => 'retire',
            'from_level_id' => $r1->id,
            'effective_from' => '2026-07-01',
            'ids' => [$person->id],
            'reason' => str_repeat('x', 256),
        ])->assertSessionHasErrors('reason');

        $this->assertTrue($person->fresh()->active, 'A refused submission must write nothing.');
    }

    public function test_every_written_span_carries_the_batch_id_reason_and_actor(): void
    {
        $r1 = $this->level('R1');
        $r2 = $this->level('R2');
        $person = Person::factory()->create();
        LevelAssignment::assign($person, $r1, '2026-01-01');

        $this->actingAs($this->admin)->post('/admin/promotion/commit', [
            'action' => 'promote',
            'from_level_id' => $r1->id,
            'to_level_id' => $r2->id,
            'effective_from' => '2026-07-01',
            'ids' => [$person->id],
            'reason' => 'Annual promotion 2026',
        ])->assertRedirect();

        $span = PersonLevel::where('person_id', $person->id)->where('level_id', $r2->id)->firstOrFail();
        $this->assertNotNull($span->promotion_batch_id);
        $this->assertSame('Annual promotion 2026', $span->reason);
        $this->assertSame((int) $this->admin->id, $span->created_by);
    }

    public function test_the_retire_cohort_action_deactivates_and_closes_spans_without_a_target_level(): void
    {
        $r4 = $this->level('R4');
        $person = Person::factory()->create(['active' => true]);
        LevelAssignment::assign($person, $r4, '2026-01-01');

        $this->actingAs($this->admin)->post('/admin/promotion/commit', [
            'action' => 'retire',
            'from_level_id' => $r4->id,
            'effective_from' => '2026-07-01',
            'ids' => [$person->id],
        ])->assertRedirect();

        $this->assertFalse($person->fresh()->active);

        $span = PersonLevel::where('person_id', $person->id)->where('level_id', $r4->id)->firstOrFail();
        $this->assertSame('2026-06-30', $span->effective_to->format('Y-m-d'));
    }

    public function test_a_promotion_is_addressable_by_batch(): void
    {
        $r1 = $this->level('R1');
        $r2 = $this->level('R2');
        $a = Person::factory()->create();
        $b = Person::factory()->create();
        LevelAssignment::assign($a, $r1, '2026-01-01');
        LevelAssignment::assign($b, $r1, '2026-01-01');

        $this->actingAs($this->admin)->post('/admin/promotion/commit', [
            'action' => 'promote',
            'from_level_id' => $r1->id,
            'to_level_id' => $r2->id,
            'effective_from' => '2026-07-01',
            'ids' => [$a->id, $b->id],
        ])->assertRedirect();

        $batchId = PersonLevel::where('person_id', $a->id)->where('level_id', $r2->id)->value('promotion_batch_id');
        $this->assertNotNull($batchId);
        $this->assertSame(2, PersonLevel::where('promotion_batch_id', $batchId)->count());
    }

    // --- The audit ----------------------------------------------------------------------------

    public function test_one_summary_row_plus_one_row_per_person(): void
    {
        $r1 = $this->level('R1');
        $r2 = $this->level('R2');
        $a = Person::factory()->create();
        $b = Person::factory()->create();
        LevelAssignment::assign($a, $r1, '2026-01-01');
        LevelAssignment::assign($b, $r1, '2026-01-01');

        $this->actingAs($this->admin)->post('/admin/promotion/commit', [
            'action' => 'promote',
            'from_level_id' => $r1->id,
            'to_level_id' => $r2->id,
            'effective_from' => '2026-07-01',
            'ids' => [$a->id, $b->id],
        ])->assertRedirect();

        $this->assertSame(1, AuditLog::where('action', 'person_promotion')->count());
        $this->assertSame(2, AuditLog::where('action', 'person_level_change')->count());
    }

    public function test_the_audit_details_carry_ids_only(): void
    {
        $r1 = $this->level('R1');
        $r2 = $this->level('R2');
        $person = Person::factory()->create();
        LevelAssignment::assign($person, $r1, '2026-01-01');

        $this->actingAs($this->admin)->post('/admin/promotion/commit', [
            'action' => 'promote',
            'from_level_id' => $r1->id,
            'to_level_id' => $r2->id,
            'effective_from' => '2026-07-01',
            'ids' => [$person->id],
        ])->assertRedirect();

        $summary = AuditLog::where('action', 'person_promotion')->firstOrFail();
        $this->assertMatchesRegularExpression(
            '/^batch=[0-9a-f-]{36};from_level=\d+;to_level=\d+;n=\d+$/',
            (string) $summary->detail
        );
        $this->assertStringNotContainsString('R1', (string) $summary->detail);
        $this->assertStringNotContainsString('R2', (string) $summary->detail);

        $item = AuditLog::where('action', 'person_level_change')->firstOrFail();
        $this->assertMatchesRegularExpression(
            '/^person=\d+;level=\d+;batch=[0-9a-f-]{36}$/',
            (string) $item->detail
        );
        $this->assertStringNotContainsString('R1', (string) $item->detail);
        $this->assertStringNotContainsString('R2', (string) $item->detail);
    }

    /**
     * Decision H: forty critical alerts for one routine annual act is an alert channel nobody
     * reads on the forty-first — only the SUMMARY action is watched.
     */
    public function test_only_the_summary_action_is_on_the_anomaly_watch_list(): void
    {
        $source = (string) file_get_contents(app_path('Console/Commands/AuditAnomalies.php'));

        $this->assertStringContainsString("'person_promotion'", $source);
        $this->assertStringNotContainsString("'person_level_change'", $source);
    }

    public function test_a_resident_is_refused(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/promotion')->assertForbidden();
    }
}
