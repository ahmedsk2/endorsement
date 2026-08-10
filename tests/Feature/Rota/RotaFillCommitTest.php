<?php

namespace Tests\Feature\Rota;

use App\Models\AuditLog;
use App\Models\Level;
use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Support\LevelAssignment;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\RotaFill;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Munawib MR-06's bulk moves, COMMITTED (P1d-2 Task 8). Task 7 proved `RotaFill::plan()` writes
 * nothing; this file proves `apply()` writes — and proves the four properties that make a bulk
 * write safe on the most destructive surface in the rota:
 *
 *  1. **The whole set, or none of it** (P1 finding 12, `AccessControlController::updateRoles()`).
 *     One transaction, the delta computed inside it, a failure part-way through leaving the table
 *     byte-identical. "412 of 780 applied" is the failure this shape exists to prevent.
 *  2. **The commit is pinned to the plan the operator saw.** `RosterImport::commit()` pins itself to
 *     the sha256 of the exact bytes its preview ran against; a fill's "bytes" are the rota rows the
 *     plan was computed against, so the digest is taken over the plan's own state projection. A grid
 *     that changed underneath is a refusal naming the fix, never a silent apply.
 *  3. **One audit row per OPERATION**, ids and counts only, appended AFTER the transaction commits
 *     (finding 8) — never one per cell, which would be 780 chain appends serialising the audit tail
 *     (P1 finding 11) and 780 findings in one `OpsAlert` body.
 *  4. **`RotaAssignment` is still the only writer** (finding 9): the fill dispatches through it and
 *     nests inside its transactions deliberately. `RotaWritersAreSingularTest` fails the build for a
 *     second writer; this file asserts the behavioural half — a writer refusal reaches the browser
 *     as a 422, never a raw 500 (P1b finding 14).
 *
 * Same fixture discipline as `RotaFillPlanTest`: periods of DELIBERATELY different lengths (MR-01,
 * Decision E), and every level/unit code `XF`-prefixed so nothing collides with `ReferenceSeeder`.
 */
class RotaFillCommitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);

        $this->admin = User::factory()->create(['position' => 0]);
    }

    public function test_a_fill_writes_every_planned_cell_through_the_one_writer(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $peerA = $this->person($junior, $periods[0]);
        $peerB = $this->person($junior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unit);

        $plan = RotaFill::plan(RotaFill::FILL_DOWN_COLUMN, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
        ], []);

        $this->postFill([
            'op' => RotaFill::FILL_DOWN_COLUMN,
            'source_person_id' => $source->getKey(),
            'source_period_id' => $periods[0]->getKey(),
            'digest' => $plan['digest'],
        ])->assertRedirect();

        foreach ([$peerA, $peerB] as $peer) {
            $rows = $this->spansOf($peer, $periods[0]);

            $this->assertCount(1, $rows, 'every planned cell must be written');
            $this->assertSame((int) $unit->getKey(), (int) $rows[0]->unit_id);
            $this->assertSame($periods[0]->starts_on->format('Y-m-d'), $rows[0]->starts_on->format('Y-m-d'));
            $this->assertSame($periods[0]->ends_on->format('Y-m-d'), $rows[0]->ends_on->format('Y-m-d'));
        }
    }

    public function test_a_fill_down_of_a_split_reproduces_the_split_verbatim(): void
    {
        [$periods, $unitA] = $this->seedYear();
        $unitB = Unit::create(['code' => 'XFB', 'name' => 'Fill Unit B', 'active' => true]);
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $peer = $this->person($junior, $periods[0]);

        $spans = $this->splitSpans($periods[0], $unitA, $unitB);
        RotaAssignment::split($source, $periods[0], $spans);

        $this->fill(RotaFill::FILL_DOWN_COLUMN, [
            'source_person_id' => $source->getKey(),
            'source_period_id' => $periods[0]->getKey(),
        ])->assertRedirect();

        $this->assertSame($spans, $this->tuplesOf($peer, $periods[0]));
    }

    /**
     * The client sends an operation and a source CELL, never spans. A body carrying a unit, a date
     * or a whole span set is ignored outright — the spans come from the source cell, server-side,
     * which is what makes a forged request unable to write anything the operator did not confirm.
     */
    public function test_the_spans_come_from_the_source_cell_and_never_from_the_request(): void
    {
        [$periods, $unit] = $this->seedYear();
        $forged = Unit::create(['code' => 'XFZ', 'name' => 'Forged Unit', 'active' => true]);
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $peer = $this->person($junior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unit);

        $this->fill(RotaFill::FILL_DOWN_COLUMN, [
            'source_person_id' => $source->getKey(),
            'source_period_id' => $periods[0]->getKey(),
            // Every one of these is a lie the server must not read.
            'unit_id' => $forged->getKey(),
            'spans' => [[
                'unit_id' => $forged->getKey(),
                'starts_on' => $periods[0]->starts_on->format('Y-m-d'),
                'ends_on' => $periods[0]->starts_on->addDays(2)->format('Y-m-d'),
            ]],
            'targets' => [['person_id' => $peer->getKey(), 'unit_id' => $forged->getKey()]],
        ])->assertRedirect();

        $rows = $this->spansOf($peer, $periods[0]);

        $this->assertCount(1, $rows);
        $this->assertSame((int) $unit->getKey(), (int) $rows[0]->unit_id, 'the request forged a unit and the server took it');
        $this->assertSame($periods[0]->ends_on->format('Y-m-d'), $rows[0]->ends_on->format('Y-m-d'));
    }

    /**
     * `RosterImport`'s discipline, applied to a plan instead of a file: the confirm is pinned to the
     * exact state the preview was computed against. A colleague assigning a cell between the preview
     * and the confirm changes what the fill would do, and the operator confirmed the other one.
     */
    public function test_a_fill_is_refused_when_the_grid_changed_under_the_operator(): void
    {
        [$periods, $unitA] = $this->seedYear();
        $unitB = Unit::create(['code' => 'XFB', 'name' => 'Fill Unit B', 'active' => true]);
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $peer = $this->person($junior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unitA);

        $stale = RotaFill::plan(RotaFill::FILL_DOWN_COLUMN, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
        ], [])['digest'];

        // Somebody else edits the source cell while the operator is reading the preview.
        RotaAssignment::set($source, $periods[0], $unitB);

        $before = $this->assignmentDigest();

        $response = $this->postFill([
            'op' => RotaFill::FILL_DOWN_COLUMN,
            'source_person_id' => $source->getKey(),
            'source_period_id' => $periods[0]->getKey(),
            'digest' => $stale,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('digest');

        $this->assertSame([], $this->tuplesOf($peer, $periods[0]), 'a stale confirm must write nothing at all');
        $this->assertSame($before, $this->assignmentDigest());
    }

    /**
     * The digest pins the WORLD, not the operator's own answers. Ticking a per-cell confirmation is
     * the operator deciding, not the grid moving — if it invalidated the pin, every tick would cost
     * a re-preview round trip and the master "overwrite all splits" control could not work at all.
     */
    public function test_confirming_a_cell_does_not_invalidate_the_digest(): void
    {
        [$periods, $unitA] = $this->seedYear();
        $unitB = Unit::create(['code' => 'XFB', 'name' => 'Fill Unit B', 'active' => true]);
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $peer = $this->person($junior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unitA);
        RotaAssignment::split($peer, $periods[0], $this->splitSpans($periods[0], $unitA, $unitB));

        $args = [RotaFill::FILL_DOWN_COLUMN, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
        ]];

        $key = $peer->getKey().':'.$periods[0]->getKey();

        $unconfirmed = RotaFill::plan($args[0], $args[1], []);
        $confirmed = RotaFill::plan($args[0], $args[1], [$key => true]);

        // Non-vacuity: the confirmation genuinely changed the plan's outcome for that cell.
        $this->assertNotSame($unconfirmed['summary'], $confirmed['summary']);
        $this->assertSame($unconfirmed['digest'], $confirmed['digest']);

        $this->postFill([
            'op' => RotaFill::FILL_DOWN_COLUMN,
            'source_person_id' => $source->getKey(),
            'source_period_id' => $periods[0]->getKey(),
            'confirmations' => [$key => true],
            'digest' => $unconfirmed['digest'],
        ])->assertRedirect();

        $this->assertCount(1, $this->spansOf($peer, $periods[0]));
    }

    /**
     * ALL OR NONE. A writer that throws on the third of five cells must leave the table exactly as
     * it was — including the spans the first two cells REPLACED, which `RotaAssignment` deletes
     * before it inserts.
     */
    public function test_a_failure_part_way_through_rolls_the_whole_fill_back(): void
    {
        [$periods, $unitA] = $this->seedYear();
        $unitB = Unit::create(['code' => 'XFB', 'name' => 'Fill Unit B', 'active' => true]);
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $peers = [];

        for ($i = 0; $i < 5; $i++) {
            $peer = $this->person($junior, $periods[0]);
            $peers[] = $peer;
            // Every peer already holds something, so a partial apply would also DESTROY.
            RotaAssignment::set($peer, $periods[0], $unitB);
        }

        RotaAssignment::set($source, $periods[0], $unitA);

        $before = $this->assignmentDigest();
        $auditBefore = AuditLog::query()->count();

        $written = 0;
        MasterRotaAssignment::creating(function () use (&$written): void {
            if (++$written === 3) {
                throw new RuntimeException('injected failure part way through the fill');
            }
        });

        $response = $this->fill(RotaFill::FILL_DOWN_COLUMN, [
            'source_person_id' => $source->getKey(),
            'source_period_id' => $periods[0]->getKey(),
        ]);

        // P1b finding 14: a writer/model refusal reaches the browser as a 422, never a raw 500.
        $response->assertStatus(422);

        $this->assertGreaterThan(2, $written, 'the fixture must fail PART WAY, not before the first write');
        $this->assertSame($before, $this->assignmentDigest(), 'a failed fill must leave every row exactly as it was');
        $this->assertSame($auditBefore, AuditLog::query()->count(), 'a failed fill writes no audit row at all');
    }

    public function test_one_audit_row_per_operation_not_one_per_cell(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);

        for ($i = 0; $i < 40; $i++) {
            $this->person($junior, $periods[0]);
        }

        RotaAssignment::set($source, $periods[0], $unit);

        $before = AuditLog::query()->count();

        $this->fill(RotaFill::FILL_DOWN_COLUMN, [
            'source_person_id' => $source->getKey(),
            'source_period_id' => $periods[0]->getKey(),
        ])->assertRedirect();

        // FORTY-ONE, not forty: `User::factory()` links every account to a `people` row (P0c), so
        // the acting administrator is themselves on the active roster and therefore a row in this
        // column. That is correct — a fill-down fills the column it is looking at — and the number
        // is stated here rather than smoothed away, because a test that computed its own expected
        // count from the plan would assert nothing about the count at all.
        $this->assertSame(41, MasterRotaAssignment::query()->where('period_id', $periods[0]->getKey())->count() - 1);
        $this->assertSame($before + 1, AuditLog::query()->count(), 'forty-one cells, ONE audit row');

        $row = AuditLog::query()->latest('id')->first();

        $this->assertSame('rota_fill', $row->action);
        $this->assertSame(
            'op=fill_down_column'
            .';source_person='.$source->getKey()
            .';source_period='.$periods[0]->getKey()
            .';target_period=none'
            .';targets=41;assigned=41;replaced=0;unchanged=0;skipped=0',
            $row->detail,
        );
        $this->assertStringNotContainsString($source->full_name, (string) $row->detail);
        $this->assertStringNotContainsString($unit->code, (string) $row->detail);
        $this->assertStringNotContainsString($periods[0]->label, (string) $row->detail);
    }

    /**
     * Finding 8. `AuditLog::record()` opens its own transaction and LOCKS THE CHAIN TAIL, so calling
     * it from inside the fill's transaction would hold that lock for the fill's whole duration for
     * no benefit — a failed fill rolls back regardless of when inside it a row was written.
     *
     * Asserting "no row after a failure" would not prove this: an audit row written INSIDE the
     * transaction would roll back too and the test would pass either way. What distinguishes them is
     * the transaction depth at the moment the row is appended — 1 (its own) if the fill has already
     * committed, 2 if the fill is still holding one open.
     */
    public function test_the_audit_row_is_appended_outside_the_fills_transaction(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $this->person($junior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unit);

        // Measured, not assumed: `RefreshDatabase` runs the whole test inside a transaction of its
        // own, so the ambient depth is 1 here and `AuditLog::record()`'s own transaction makes it 2.
        // Hard-coding "2" would pin this test to that detail of the test harness; ambient + 1 is the
        // property. If the fill were still holding its transaction open it would read ambient + 2.
        $ambient = DB::transactionLevel();

        $depths = [];
        AuditLog::created(function (AuditLog $row) use (&$depths): void {
            if ($row->action === 'rota_fill') {
                $depths[] = DB::transactionLevel();
            }
        });

        $this->fill(RotaFill::FILL_DOWN_COLUMN, [
            'source_person_id' => $source->getKey(),
            'source_period_id' => $periods[0]->getKey(),
        ])->assertRedirect();

        $this->assertSame([$ambient + 1], $depths,
            'the rota_fill row must be appended with only AuditLog::record()\'s own transaction open — '
            .'one level deeper means the fill was still holding the chain tail (finding 8).');
    }

    public function test_a_split_target_is_not_overwritten_without_its_confirmation(): void
    {
        [$periods, $unitA] = $this->seedYear();
        $unitB = Unit::create(['code' => 'XFB', 'name' => 'Fill Unit B', 'active' => true]);
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $peer = $this->person($junior, $periods[0]);
        $bystander = $this->person($junior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unitA);
        $spans = $this->splitSpans($periods[0], $unitA, $unitB);
        RotaAssignment::split($peer, $periods[0], $spans);

        $this->fill(RotaFill::FILL_DOWN_COLUMN, [
            'source_person_id' => $source->getKey(),
            'source_period_id' => $periods[0]->getKey(),
        ])->assertRedirect();

        $this->assertSame($spans, $this->tuplesOf($peer, $periods[0]),
            'deliberate split work must survive an unconfirmed fill, byte for byte');

        // Non-vacuity: a fill that wrote NOTHING would satisfy the assertion above. The rest of the
        // column must have been filled for the skip to mean anything.
        $this->assertCount(1, $this->spansOf($bystander, $periods[0]));
    }

    public function test_a_stale_person_is_never_assigned_into(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $departed = $this->person($junior, $periods[0]);
        $bystander = $this->person($junior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unit);
        RotaAssignment::set($departed, $periods[1], $unit);
        $departed->forceFill(['active' => false])->save();

        $this->fill(RotaFill::FILL_DOWN_COLUMN, [
            'source_person_id' => $source->getKey(),
            'source_period_id' => $periods[0]->getKey(),
        ])->assertRedirect();

        $this->assertSame([], $this->tuplesOf($departed, $periods[0]),
            'a fill never assigns to somebody who has left the roster (finding 10)');

        // Non-vacuity: a fill that wrote nothing at all would satisfy the assertion above.
        $this->assertCount(1, $this->spansOf($bystander, $periods[0]));
    }

    /**
     * The one operation with a different request shape — no source PERSON (it moves a whole column)
     * and a required target period — and the one where "landed on the target's own bounds" is
     * observable: block 2 is 35 days long and block 1 is 28, so a copy that reused the source dates
     * would leave the target seven days short.
     */
    public function test_a_copy_period_lands_on_the_targets_own_bounds_and_names_no_source_person(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $personA = $this->person($junior, $periods[0]);
        $personB = $this->person($junior, $periods[0]);

        RotaAssignment::set($personA, $periods[0], $unit);
        RotaAssignment::set($personB, $periods[0], $unit);

        $before = AuditLog::query()->count();

        $this->fill(RotaFill::COPY_PERIOD, [
            'source_period_id' => $periods[0]->getKey(),
            'target_period_id' => $periods[1]->getKey(),
        ])->assertRedirect();

        foreach ([$personA, $personB] as $person) {
            $this->assertSame([[
                'unit_id' => (int) $unit->getKey(),
                'starts_on' => $periods[1]->starts_on->format('Y-m-d'),
                'ends_on' => $periods[1]->ends_on->format('Y-m-d'),
            ]], $this->tuplesOf($person, $periods[1]));
        }

        $this->assertSame($before + 1, AuditLog::query()->count());

        $this->assertSame(
            'op=copy_period;source_person=none'
            .';source_period='.$periods[0]->getKey()
            .';target_period='.$periods[1]->getKey()
            .';targets=2;assigned=2;replaced=0;unchanged=0;skipped=0',
            AuditLog::query()->latest('id')->first()->detail,
        );
    }

    public function test_a_fill_across_writes_every_later_period_of_the_year(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $person = $this->person($junior, $periods[0]);
        RotaAssignment::set($person, $periods[2], $unit);

        $this->fill(RotaFill::FILL_ACROSS, [
            'source_person_id' => $person->getKey(),
            'source_period_id' => $periods[2]->getKey(),
        ])->assertRedirect();

        // Forwards only: the two later blocks, each on its OWN bounds, and nothing behind it.
        foreach ([3, 4] as $index) {
            $this->assertSame([[
                'unit_id' => (int) $unit->getKey(),
                'starts_on' => $periods[$index]->starts_on->format('Y-m-d'),
                'ends_on' => $periods[$index]->ends_on->format('Y-m-d'),
            ]], $this->tuplesOf($person, $periods[$index]));
        }

        $this->assertSame([], $this->tuplesOf($person, $periods[0]));
        $this->assertSame([], $this->tuplesOf($person, $periods[1]));
    }

    public function test_the_preview_route_writes_nothing(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $this->person($junior, $periods[0]);
        $this->person($junior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unit);

        $before = $this->assignmentDigest();
        $auditBefore = AuditLog::query()->count();

        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
            ->post('/admin/rota/fill/preview', [
                'op' => RotaFill::FILL_DOWN_COLUMN,
                'source_person_id' => $source->getKey(),
                'source_period_id' => $periods[0]->getKey(),
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('rota_fill_preview');

        $preview = session('rota_fill_preview');

        // Two peers plus the acting administrator's own roster row (`User::factory()` links every
        // account to a `people` row since P0c) — the same column the editor would show.
        $this->assertCount(3, $preview['targets']);
        $this->assertNotSame('', (string) $preview['digest']);
        $this->assertArrayNotHasKey('context', $preview, 'a preview never carries Eloquent models into props (Decision C)');

        $this->assertSame($before, $this->assignmentDigest());
        $this->assertSame($auditBefore, AuditLog::query()->count());
    }

    public function test_a_resident_cannot_fill(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $this->person($junior, $periods[0]);
        RotaAssignment::set($source, $periods[0], $unit);

        $resident = User::factory()->create(['position' => 4]);

        $body = [
            'op' => RotaFill::FILL_DOWN_COLUMN,
            'source_person_id' => $source->getKey(),
            'source_period_id' => $periods[0]->getKey(),
            'digest' => 'whatever',
        ];

        foreach (['/admin/rota/fill/preview', '/admin/rota/fill'] as $url) {
            $this->actingAs($resident)
                ->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
                ->post($url, $body)
                ->assertStatus(403);
        }
    }

    public function test_an_unknown_operation_is_a_422_and_writes_nothing(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $this->person($junior, $periods[0]);
        RotaAssignment::set($source, $periods[0], $unit);

        $before = $this->assignmentDigest();

        $response = $this->postFill([
            'op' => 'fill_sideways',
            'source_person_id' => $source->getKey(),
            'source_period_id' => $periods[0]->getKey(),
            'digest' => 'whatever',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('op');
        $this->assertSame($before, $this->assignmentDigest());
    }

    /**
     * P1d-2 Task 9 — THE PLAN HAS TO REACH THE SCREEN, and until this task it could not.
     *
     * `back()->with('rota_fill_preview', …)` puts the plan in the session flash; Inertia does not
     * forward session flash to a page on its own. Every other one-shot channel in this app is
     * enumerated in `HandleInertiaRequests::share()`'s `flash` array (`roster_preview`,
     * `promotion_preview`, `bulk_report`, …) precisely because an unlisted key is invisible to
     * every screen. Task 8 shipped the two flashes and no share entry, so the preview was written
     * into a session nothing ever read — a whole feature reachable only from a feature test.
     *
     * Asserted through a REAL second request rather than by reading `session()`, because "the
     * controller flashed it" and "the page can see it" are different claims and only the second
     * one is the feature.
     */
    public function test_the_preview_and_the_result_reach_the_screen_as_shared_flash_props(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $this->person($junior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unit);

        $body = [
            'op' => RotaFill::FILL_DOWN_COLUMN,
            'source_person_id' => $source->getKey(),
            'source_period_id' => $periods[0]->getKey(),
        ];

        $this->actingAs($this->admin)
            ->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
            ->post('/admin/rota/fill/preview', $body)
            ->assertRedirect();

        // `withHeaders()` persists for the REST OF THE TEST (it sets the case's default headers,
        // not one request's), so an un-flushed `X-Inertia: true` makes the next GET a 409 version
        // mismatch rather than a page. Flushed here so the assertion below reads the real rendered
        // page an operator's browser would get.
        $this->flushHeaders();

        $this->actingAs($this->admin)
            ->get('/admin/rota?year=2026-2027')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('flash.rota_fill_preview.targets', 2)
                ->where('flash.rota_fill_preview.op', RotaFill::FILL_DOWN_COLUMN)
                ->has('flash.rota_fill_preview.digest')
                ->has('flash.rota_fill_preview.summary')
                // Decision C again, at the props layer this time: a preview reaching a SCREEN is
                // the exact moment an Eloquent model in the payload would start emitting contact
                // fields nobody put there.
                ->missing('flash.rota_fill_preview.context')
            );

        $plan = RotaFill::plan($body['op'], [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
            'target_period_id' => null,
        ], []);

        $this->postFill($body + ['digest' => $plan['digest']])->assertRedirect();

        $this->flushHeaders();

        $this->actingAs($this->admin)
            ->get('/admin/rota?year=2026-2027')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.rota_fill_result.applied', 2)
                ->missing('flash.rota_fill_result.context')
            );
    }

    /**
     * P1d-2 Task 9 — THE PREVIEW REQUEST CARRIES ITS OWN COST, and it is pinned here.
     *
     * `RotaFillPlanTest::test_the_plan_is_a_bounded_number_of_queries` bounds `RotaFill::plan()` in
     * isolation (4–6 on this shape). This bounds the whole HTTP round trip an operator's click
     * actually makes — session, the capability catalogue, `RotaFillRequest`'s `exists` rules and the
     * plan itself — because that is the number that grows when somebody adds a per-target lookup to
     * the CONTROLLER or the REQUEST rather than to `RotaFill`, where Task 7's bound would catch it.
     *
     * Measured by reading a deliberately unreachable `assertLessThan(1, …)` and restoring it:
     * **7 queries**, warm, for a 44-target column on a 13-period year — and the same 7 for a
     * 4-target one. A COLD first request in the process measured 10; the three-query difference is
     * session and capability-catalogue warmup, the same effect Task 3's amendment measured as a
     * 16→11 drop on `/rota`, which is why the warm-up below is discarded and why the bound is 12
     * rather than an equality on 7.
     *
     * THE EQUALITY IS THE REAL GUARD, and it is the second half. The same operation is measured
     * twice, once against a four-person column and once against a forty-four-person one, and the
     * two counts must be IDENTICAL: a bound of 15 is met by an N+1 that happens to run on a small
     * fixture, and 40 is not a number that would make one obvious. A warm-up request runs first so
     * neither measurement carries the process's one-off session and capability reads.
     */
    public function test_the_preview_request_is_a_bounded_number_of_queries(): void
    {
        [$periods, $unit] = $this->seedYear(13);
        [$junior, $senior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        RotaAssignment::set($source, $periods[0], $unit);

        for ($i = 0; $i < 3; $i++) {
            RotaAssignment::set($this->person($junior, $periods[0]), $periods[0], $unit);
        }

        $preview = function () use ($source, $periods): array {
            $this->flushHeaders();

            DB::enableQueryLog();
            DB::flushQueryLog();

            $this->actingAs($this->admin)
                ->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
                ->post('/admin/rota/fill/preview', [
                    'op' => RotaFill::FILL_DOWN_COLUMN,
                    'source_person_id' => $source->getKey(),
                    'source_period_id' => $periods[0]->getKey(),
                ])
                ->assertRedirect();

            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$count, count(session('rota_fill_preview')['targets'])];
        };

        // Warm-up, discarded: the first request of a process resolves the session and the
        // capability catalogue, which is not grid work and would make the two figures below
        // incomparable.
        $preview();

        [$smallCount, $smallTargets] = $preview();

        for ($i = 0; $i < 40; $i++) {
            RotaAssignment::set($this->person($i % 2 === 0 ? $junior : $senior, $periods[0]), $periods[0], $unit);
        }

        [$largeCount, $largeTargets] = $preview();

        // Non-vacuity, both halves: a budget is trivially met by a request that planned nothing,
        // and an equality is trivially met by two columns of the same size.
        $this->assertGreaterThan(3, $smallTargets);
        $this->assertGreaterThan($smallTargets + 30, $largeTargets);

        $this->assertSame(
            $smallCount,
            $largeCount,
            "the fill preview ran {$smallCount} queries for {$smallTargets} targets and {$largeCount} for {$largeTargets} — "
            .'the cost must be constant in the number of targets, which is the 780-query preview this bound exists to catch.'
        );

        $this->assertLessThan(12, $largeCount, "the fill preview ran {$largeCount} queries for {$largeTargets} targets.");
    }

    // ---------------------------------------------------------------- fixture

    /** POST the confirm with a digest taken from a fresh plan of the same inputs. */
    private function fill(string $op, array $body): \Illuminate\Testing\TestResponse
    {
        $plan = RotaFill::plan($op, [
            'person_id' => $body['source_person_id'] ?? null,
            'period_id' => $body['source_period_id'] ?? null,
            'target_period_id' => $body['target_period_id'] ?? null,
        ], $body['confirmations'] ?? []);

        return $this->postFill($body + ['op' => $op, 'digest' => $plan['digest']]);
    }

    private function postFill(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
            ->post('/admin/rota/fill', $body);
    }

    /**
     * Periods of DIFFERENT lengths, deliberately (MR-01, Decision E).
     *
     * @return array{0: list<Period>, 1: Unit}
     */
    private function seedYear(int $count = 5, string $academicYear = '2026-2027'): array
    {
        $lengths = [28, 35, 28, 21, 28];

        $periods = [];
        $start = CarbonImmutable::parse('2026-07-01');

        for ($i = 1; $i <= $count; $i++) {
            $days = $lengths[($i - 1) % count($lengths)];
            $end = $start->addDays($days - 1);

            $periods[] = Period::create([
                'academic_year' => $academicYear,
                'kind' => Period::WEEK_BLOCK,
                'position' => $i,
                'label' => "Block {$i}",
                'starts_on' => $start->format('Y-m-d'),
                'ends_on' => $end->format('Y-m-d'),
            ]);

            $start = $end->addDay();
        }

        return [$periods, Unit::create(['code' => 'XFA', 'name' => 'Fill Unit A', 'active' => true])];
    }

    /** @return array{0: Level, 1: Level} */
    private function twoLevels(): array
    {
        return [
            Level::factory()->create(['code' => 'XF1', 'display_order' => 10]),
            Level::factory()->create(['code' => 'XF2', 'display_order' => 20]),
        ];
    }

    private function person(Level $level, Period $from): Person
    {
        $person = Person::factory()->create();
        LevelAssignment::assign($person, $level, $from->starts_on->format('Y-m-d'));

        return $person;
    }

    /** @return list<array{unit_id:int, starts_on:string, ends_on:string}> */
    private function splitSpans(Period $period, Unit $a, Unit $b): array
    {
        return [
            [
                'unit_id' => $a->getKey(),
                'starts_on' => $period->starts_on->format('Y-m-d'),
                'ends_on' => $period->starts_on->addDays(9)->format('Y-m-d'),
            ],
            [
                'unit_id' => $b->getKey(),
                'starts_on' => $period->starts_on->addDays(13)->format('Y-m-d'),
                'ends_on' => $period->ends_on->format('Y-m-d'),
            ],
        ];
    }

    /** @return list<MasterRotaAssignment> */
    private function spansOf(Person $person, Period $period): array
    {
        return MasterRotaAssignment::query()
            ->where('person_id', $person->getKey())
            ->where('period_id', $period->getKey())
            ->orderBy('starts_on')
            ->get()
            ->all();
    }

    /** @return list<array{unit_id:int, starts_on:string, ends_on:string}> */
    private function tuplesOf(Person $person, Period $period): array
    {
        return array_map(fn (MasterRotaAssignment $row): array => [
            'unit_id' => (int) $row->unit_id,
            'starts_on' => $row->starts_on->format('Y-m-d'),
            'ends_on' => $row->ends_on->format('Y-m-d'),
        ], $this->spansOf($person, $period));
    }

    /** Count first (the readable half of a failure), then a hash — never 400 serialised rows. */
    private function assignmentDigest(): string
    {
        $rows = MasterRotaAssignment::query()
            ->orderBy('id')
            ->get(['id', 'person_id', 'period_id', 'unit_id', 'starts_on', 'ends_on'])
            ->toJson();

        return substr_count($rows, '"id":').':'.md5($rows);
    }
}
