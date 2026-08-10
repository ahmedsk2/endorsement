<?php

namespace App\Support\Rota;

use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Vacation;
use App\Support\Calendar;

/**
 * Munawib MR-06's export (P1d-2 Decision G). TWO files, and this class builds both of them.
 *
 * WHY TWO FILES AND NOT ONE. `rota.csv` is one row per SPAN; `vacations.csv` is one row per
 * vacation. A single file mixing the two row shapes is either sparse — half the columns blank on
 * every row — or ambiguous, and an ambiguous file is one `App\Support\Rota\RotaImport` has to
 * GUESS the shape of. Guessing wrong is how an importer silently misreads a file.
 *
 * WHY A PERSON IS `short_name` + `full_name` AND NOTHING ELSE. `people.short_name` is the only
 * app-wide UNIQUE human handle that is not a contact field (finding 5), so it is what the importer
 * matches on; `full_name` is there for the human reading the file and is never matched on. There
 * is deliberately **no email, no phone and no `person_id`**: ids are instance-local and mean
 * nothing in another deployment (D11 makes cross-instance identity a non-question anyway), and
 * because no contact field appears in the column list at all, the "may this viewer see a phone
 * number" question never arises — the same answer Decision C gave for the grid, arrived at the
 * same way. `RotaExportTest` asserts the absence over the FILE'S OWN BYTES, not over this
 * constant: a future hand-built row reading a contact attribute straight off a `Person` would
 * satisfy a code-shaped assertion and fail that one.
 *
 * (That sentence is deliberately written around the attribute ACCESSOR SHAPE rather than quoting
 * it: `ContactFieldsAreProjectedOnceTest`'s needle list is a plain substring scan with no
 * allow-list, and it fired on this docblock's first draft for quoting the arrow-and-field form in
 * prose — finding 14's trap, in the PHP contact scan rather than the client's date scan. Caught by
 * running the suite, not by reading the guard.)
 *
 * WHY THIS CLASS AND NOT `RotaGrid`. Two reasons, both empirical.
 *  1. `RotaGrid::cellFor()` resolves a span's `unit_code` from the ACTIVE units map, so the code
 *     is NULL in exactly the retired-unit case (P1d-2 Task 5's amendment, which removed a
 *     `Rota.vue` fallback whose docblock claimed the opposite). An export built off the grid would
 *     therefore blank the unit on precisely the historical spans nobody can re-create. This class
 *     reads the code off the assignment's own `unit` relation — the source.
 *  2. The grid's row set is the year's people; the export's row set is the year's SPANS. Folding
 *     one into the other would mean walking cells to rebuild rows the database can simply return
 *     in order.
 * It is not a second definition of "which spans belong to this year" either: both start from
 * `Period::forYear()`, which stays the one answer.
 *
 * WHY THE ROWS ARE MATERIALISED RATHER THAN YIELDED. `Csv::stream()` takes any iterable and a
 * generator would keep memory flat — but the audit row states how many rows the file CONTAINS, and
 * a `StreamedResponse`'s callback does not run until after the controller has returned. A count
 * taken from a separate `COUNT(*)` could disagree with the file it claims to describe. A year's
 * spans are bounded (sixty people x thirteen periods x a span or two), so the honest count is
 * worth the array.
 *
 * STALE PEOPLE ARE IN THE FILE, deliberately. A person who is deactivated or soft-deleted but
 * still holds a span is exactly who blocks `PeriodController::destroy()`, so an administrator
 * exporting the year to find out why it will not clear has to see them; and this export sits
 * behind `rota.manage`, the same capability as the editor grid, which shows them for that same
 * reason. (Decision D hides them from the resident READ view — a different surface, a different
 * capability, a different question.) `RotaImport` answers such a row with `SKIP_UNKNOWN_PERSON`
 * and *"no longer on the active roster"*, which is finding 10's offer/write parity: named, never
 * silent. Both queries below therefore eager-load the person `withTrashed()` — without it
 * SoftDeletes' global scope resolves a retired person's relation to null and the row exports two
 * blank name columns.
 *
 * THIS CLASS WRITES NOTHING. Two reads and a pair of arrays; `RotaAssignment` and
 * `VacationBooking` remain the only writers of their tables (Decision F).
 */
final class RotaExport
{
    /** @var list<string> */
    public const ASSIGNMENT_HEADERS = [
        'academic_year',
        'period_position',
        'period_label',
        'period_starts_on',
        'period_ends_on',
        'short_name',
        'full_name',
        'unit_code',
        'starts_on',
        'ends_on',
    ];

    /**
     * No `academic_year`, and that is not an oversight: a vacation carries NO `period_id`
     * (P1d Decision C) because leave crosses period boundaries and must survive a department
     * regenerating or switching its period system. Which year a row belongs to is a range
     * intersection computed at read time — here, and again in `RotaImport` — never a stored fact.
     *
     * @var list<string>
     */
    public const VACATION_HEADERS = [
        'short_name',
        'full_name',
        'starts_on',
        'ends_on',
        'granularity',
        'source',
    ];

    /**
     * One row per span, ordered period by period and then by the person's name — the order an
     * operator reads the grid in, so a diff between two exports of the same year is legible.
     *
     * @return list<list<string>>
     */
    public static function assignments(string $academicYear): array
    {
        $periods = Period::query()->forYear($academicYear)->ordered()->get();

        if ($periods->isEmpty()) {
            return [];
        }

        $assignments = MasterRotaAssignment::query()
            ->whereIn('period_id', $periods->modelKeys())
            // `withTrashed()` on the person: a retired person's spans are still in the file (see
            // the class docblock), and without this the global scope nulls the relation rather
            // than omitting the row — two blank name columns, no error.
            ->with(['person' => fn ($query) => $query->withTrashed(), 'unit'])
            ->orderBy('starts_on')
            ->get();

        $rows = [];

        foreach ($periods as $period) {
            $forPeriod = $assignments
                ->where('period_id', $period->getKey())
                ->sortBy(fn (MasterRotaAssignment $a): string => self::readingOrder($a->person, $a->starts_on));

            foreach ($forPeriod as $assignment) {
                $rows[] = [
                    $period->academic_year,
                    (string) $period->position,
                    (string) $period->label,
                    Calendar::ymd($period->starts_on),
                    Calendar::ymd($period->ends_on),
                    (string) $assignment->person?->short_name,
                    (string) $assignment->person?->full_name,
                    // The SOURCE, not the grid: a retired unit's code survives here and is null
                    // there. See the class docblock.
                    (string) $assignment->unit?->code,
                    Calendar::ymd($assignment->starts_on),
                    Calendar::ymd($assignment->ends_on),
                ];
            }
        }

        return $rows;
    }

    /**
     * Every vacation INTERSECTING the academic year's own span — the same range test the grid
     * makes, and the only relationship a vacation has to a year (see `VACATION_HEADERS`). A leave
     * that begins in one year and ends in the next appears in both files, once each, whole: a file
     * carrying a clipped copy would re-import as a shorter holiday than the department granted.
     *
     * @return list<list<string>>
     */
    public static function vacations(string $academicYear): array
    {
        $periods = Period::query()->forYear($academicYear)->ordered()->get();

        if ($periods->isEmpty()) {
            return [];
        }

        $vacations = Vacation::query()
            ->intersecting(
                Calendar::ymd($periods->first()->starts_on),
                Calendar::ymd($periods->last()->ends_on),
            )
            ->with(['person' => fn ($query) => $query->withTrashed()])
            ->orderBy('starts_on')
            ->get()
            ->sortBy(fn (Vacation $vacation): string => self::readingOrder($vacation->person, $vacation->starts_on));

        $rows = [];

        foreach ($vacations as $vacation) {
            $rows[] = [
                (string) $vacation->person?->short_name,
                (string) $vacation->person?->full_name,
                Calendar::ymd($vacation->starts_on),
                Calendar::ymd($vacation->ends_on),
                (string) $vacation->granularity,
                (string) $vacation->source,
            ];
        }

        return $rows;
    }

    /**
     * Finding 5, surfaced BEFORE the file is generated rather than discovered when a third of the
     * re-import comes back `SKIP_UNKNOWN_PERSON`. `people.short_name` is nullable, so a person
     * without one exports a blank handle and cannot be matched on the way back in.
     *
     * Counted over the people who would actually APPEAR in a file — a row holding at least one
     * span or one vacation in this year — and NOT over the whole roster. The plan's wording is
     * "how many people in the year lack a short name", which on a `RotaGrid` row set also counts
     * every active person with nothing planned at all: a warning that fires on somebody who
     * exports no rows is a warning an administrator learns to ignore, and the count then does not
     * match the number of broken lines in the file it is warning about.
     *
     * A pure fold over the grid array the screen already holds — no query, the same property
     * `AvailabilitySummary` has (Decision B).
     *
     * @param  array{rows: list<array<string, mixed>>}  $grid  App\Support\Rota\RotaGrid::forYear() shape
     */
    public static function peopleWithoutAShortName(array $grid): int
    {
        $count = 0;

        foreach ($grid['rows'] as $row) {
            $handle = trim((string) ($row['person']['short_name'] ?? ''));

            if ($handle !== '') {
                continue;
            }

            foreach ($row['cells'] as $cell) {
                if ($cell['spans'] !== [] || $cell['vacations'] !== []) {
                    $count++;

                    break;
                }
            }
        }

        return $count;
    }

    /**
     * The download filename. Named here rather than at the two call sites so the two files cannot
     * drift into two naming conventions — and deliberately NOT in the audit row, which carries
     * ids, field names and counts only (Decision H).
     */
    public static function filename(string $file, string $academicYear): string
    {
        return ($file === 'vacations' ? 'vacations-' : 'rota-').$academicYear.'.csv';
    }

    /**
     * Whether this academic year exists at all. A `?year=` naming no periods is a 404, never a
     * header-only file: a typo must not be indistinguishable from a year whose rota was cleared.
     */
    public static function yearExists(?string $academicYear): bool
    {
        return is_string($academicYear)
            && $academicYear !== ''
            && Period::query()->forYear($academicYear)->exists();
    }

    /**
     * The reading order for both files: the person's name, then the row's own start date. Built as
     * ONE composite key rather than a multi-comparison sort because ordering here is a legibility
     * property, not a contract — nothing downstream depends on it, and `RotaImport` reassembles a
     * split cell from its contributing lines whatever order they arrive in (finding 12). `\x1f`
     * (unit separator) is the joiner because it cannot occur in a name.
     */
    private static function readingOrder(?Person $person, mixed $startsOn): string
    {
        return (string) $person?->full_name."\x1f".Calendar::ymd($startsOn);
    }
}
