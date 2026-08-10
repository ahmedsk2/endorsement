<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Support\Calendar;
use App\Support\PeriodGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Admin → Structure → Periods (cap:structure.manage). Munawib MR-01.
 *
 * FINDING 4: `PeriodGenerator` had ZERO production callers before this controller — the
 * `periods` table could never be populated by the application, and P1d's rota grid has no
 * columns without it. `store()` (generate) and `destroy()` (delete a year) are new production
 * writers this plan adds; the P1 plan's own P1b scope named only a preview.
 *
 * The preview (`index()`) and the commit (`store()`) share ONE private `buildRun()` — never two
 * call sites with two argument sets, the same discipline `SignoffPickers` and
 * `PeriodGenerator::assertMonthAligned()` both exist to enforce elsewhere in this codebase.
 *
 * FINDING 14: `Period::booted()`'s overlap guard throws a `RuntimeException`, not a
 * `ValidationException`, and it only fires within ONE academic year (it is scoped by
 * `academic_year` equality, by design — periods.institution_id's own migration docblock).
 * A genuine day-collision against an ADJACENT, already-persisted year would never trip that
 * guard at all, so `store()` pre-checks `PeriodGenerator::warningsAgainstNeighbours()`'s two
 * candidate periods for a REAL overlap (not merely a gap — reconnaissance finding 7 keeps a gap
 * a warning, never a block) and converts it into a 422 before any `Period::create()` runs. The
 * model guard stays as the last line, still wrapped in a transaction/try-catch here so a
 * same-year race (two admins generating at once) cannot reach the user as a raw 500 either.
 *
 * `periods` are pure schedule STRUCTURE — no PHI — so `destroy()` is a genuine hard delete, unlike
 * every clinical/identity table in this system. P1d's `master_rota_assignments` is the first row
 * that DOES point at a period; it is schedule structure too (Decision E — no soft delete there
 * either), so the hard delete stays genuine, but it is now refused outright while any assignment
 * references the year rather than silently taking a planned rota with it. That refusal is exactly
 * what keeps `period_type`/`academic_year_start`'s unlock on the calendar screen (Decision D)
 * honest: "delete this academic year's periods first — which is itself refused while the master
 * rota references them" is a real, working instruction, not a trap.
 */
class PeriodController extends Controller
{
    public function index(Request $request): Response
    {
        $start = Calendar::academicYearStart();

        // `?next_year_start[]=…` is an ARRAY, and the string cast this line used to carry raised
        // `Array to string conversion` — an `ErrorException`, rendered as a 500, on the screen that
        // generates a department's whole academic year. An unusable value is treated exactly as an
        // absent one, which is what `tryParse()` already does for an unparseable string.
        $requestedNextYearStart = $request->query('next_year_start');
        $nextYearStart = Calendar::tryParse(is_string($requestedNextYearStart) ? $requestedNextYearStart : '');

        $preview = $start === null ? null : $this->preview($start, $nextYearStart);

        return Inertia::render('Admin/Periods', [
            'settings' => [
                'period_type' => Calendar::periodType(),
                'academic_year_start' => $start?->format(Calendar::YMD),
            ],
            'next_year_start' => $nextYearStart?->format(Calendar::YMD),
            'preview' => $preview,
            // The button is NEVER disabled by a warning (reconnaissance finding 7: the
            // department sets its own start dates) — only the commit itself can refuse.
            'generate_disabled' => $start === null,
            'years' => $this->persistedYears(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['next_year_start' => ['nullable', 'date_format:Y-m-d']]);

        $start = Calendar::academicYearStart();

        if ($start === null) {
            throw ValidationException::withMessages([
                'next_year_start' => 'Set the academic year start on the Calendar settings screen first.',
            ]);
        }

        $nextYearStart = $request->filled('next_year_start')
            ? Calendar::parse((string) $request->input('next_year_start'))
            : null;

        try {
            $periods = $this->generate($start, $nextYearStart);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['next_year_start' => $e->getMessage()]);
        }

        $lastEnd = Calendar::parse($periods[count($periods) - 1]['ends_on']);
        $academicYear = PeriodGenerator::deriveAcademicYear($start, $lastEnd);

        // Generating TWICE for the same academic year is refused outright — never a partial
        // re-write merged with what is already there. The unlock is always "delete first".
        if (Period::query()->where('academic_year', $academicYear)->exists()) {
            throw ValidationException::withMessages(['next_year_start' => "Periods for academic year "
                ."\"{$academicYear}\" already exist. Delete them first (below), then generate "
                .'again.']);
        }

        // FINDING 14: Period::booted()'s RuntimeException only fires WITHIN one academic year
        // (scoped by academic_year equality); a genuine day-collision against an ADJACENT,
        // already-persisted year would never trip it. Pre-checked and thrown as a
        // ValidationException — a real 422, negotiated the same way `$request->validate()`
        // would negotiate it — never Period::booted()'s raw RuntimeException reaching the user
        // as a 500.
        $clash = $this->overlapAgainstNeighbours($periods, $academicYear);

        if ($clash !== null) {
            throw ValidationException::withMessages(['next_year_start' => $clash]);
        }

        try {
            DB::transaction(function () use ($periods, $academicYear, $request): void {
                foreach ($periods as $row) {
                    Period::create([
                        'institution_id' => $request->user()?->institution_id,
                        'academic_year' => $academicYear,
                        'kind' => $row['kind'],
                        'position' => $row['position'],
                        'label' => $row['label'],
                        'starts_on' => $row['starts_on'],
                        'ends_on' => $row['ends_on'],
                    ]);
                }
            });
        } catch (RuntimeException $e) {
            // The model guard's last line, per its own docblock — reached only by a genuine
            // race (a same-year clash that appeared between the check above and this write).
            // DB::transaction() has already rolled back whatever landed; nothing here is a
            // partial write.
            throw ValidationException::withMessages(['next_year_start' => $e->getMessage()]);
        }

        AuditLog::record(
            'periods_generate',
            "year={$academicYear};kind=".Calendar::periodType().';count='.count($periods),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', "Generated {$academicYear}: ".count($periods).' periods.');
    }

    /**
     * Deletion requires the year to be TYPED, not a bare confirm checkbox — this is the ONLY
     * path that unlocks `period_type`/`academic_year_start` on the calendar screen, so it must
     * not be one accidental click away.
     *
     * P1d Task 4 closes the hook this docblock used to name as open: `master_rota_assignments`
     * now exists, and the delete is refused outright while any row references one of this
     * year's periods — never a partial delete of the periods an assignment does NOT reference.
     * `AssignmentIntegrityTest` and `PeriodGenerationScreenTest::
     * test_deleting_a_year_is_refused_while_a_rota_assignment_references_it` /
     * `test_deleting_a_year_still_succeeds_once_nothing_references_it` are what pin this now,
     * replacing the old `test_delete_succeeds_today_with_no_assignment_table_to_check` pin.
     *
     * `vacations` (Task 6) is deliberately NOT checked here — it carries no `period_id`
     * (Decision C), so deleting periods cannot orphan one.
     */
    public function destroy(Request $request, string $academicYear): RedirectResponse
    {
        $data = $request->validate(['confirm_academic_year' => ['required', 'string']]);

        if ($data['confirm_academic_year'] !== $academicYear) {
            throw ValidationException::withMessages([
                'confirm_academic_year' => 'Type the academic year exactly to confirm deletion.',
            ]);
        }

        $count = Period::query()->where('academic_year', $academicYear)->count();

        if ($count === 0) {
            throw ValidationException::withMessages([
                'confirm_academic_year' => "No periods found for academic year \"{$academicYear}\".",
            ]);
        }

        $blocking = MasterRotaAssignment::query()
            ->whereIn('period_id', Period::query()->where('academic_year', $academicYear)->select('id'))
            ->count();

        if ($blocking > 0) {
            throw ValidationException::withMessages([
                'confirm_academic_year' => "This academic year has {$blocking} master rota assignment(s) "
                    .'against it. Deleting its periods would delete the rota with them, and there is no '
                    .'soft delete to recover from. Clear the rota for this year first (Master Rota), then '
                    .'delete the periods.',
            ]);
        }

        Period::query()->where('academic_year', $academicYear)->delete();

        AuditLog::record(
            'periods_delete',
            "year={$academicYear};count={$count}",
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', "Deleted {$count} periods for {$academicYear}.");
    }

    /**
     * @return list<array{position:int,label:string,starts_on:string,ends_on:string,kind:string}>
     */
    private function generate(\Carbon\CarbonImmutable $start, ?\Carbon\CarbonImmutable $nextYearStart): array
    {
        return Calendar::periodType() === Institution::PERIOD_MONTHS
            ? PeriodGenerator::months($start)
            : PeriodGenerator::weekBlocks($start, Calendar::blockWeeks(), $nextYearStart);
    }

    /**
     * @return array{periods:list<array<string,mixed>>, total_days:int, warnings:list<string>, used_fallback_block:bool}
     */
    private function preview(\Carbon\CarbonImmutable $start, ?\Carbon\CarbonImmutable $nextYearStart): array
    {
        try {
            $periods = $this->generate($start, $nextYearStart);
        } catch (InvalidArgumentException $e) {
            return [
                'periods' => [],
                'total_days' => 0,
                'warnings' => [$e->getMessage()],
                'used_fallback_block' => false,
            ];
        }

        $lastEnd = Calendar::parse($periods[count($periods) - 1]['ends_on']);
        $academicYear = PeriodGenerator::deriveAcademicYear($start, $lastEnd);
        $totalDays = Calendar::parse($periods[0]['starts_on'])->diffInDays($lastEnd) + 1;

        [$previousYearLast, $nextYearFirst] = $this->neighbours($academicYear, $periods);

        // Every date a screen shows goes through Calendar::label() (UX-04) — the client
        // performs no date arithmetic at all.
        $decorated = array_map(fn (array $row): array => $row + [
            'starts_label' => Calendar::label($row['starts_on']),
            'ends_label' => Calendar::label($row['ends_on']),
        ], $periods);

        return [
            'periods' => $decorated,
            'total_days' => (int) $totalDays,
            'warnings' => PeriodGenerator::warningsAgainstNeighbours($periods, $previousYearLast, $nextYearFirst),
            // A preview convenience ONLY (P1a Task 8's amendment): the 35-day nominal length is
            // right precisely when the next year's real start is not known yet.
            'used_fallback_block' => Calendar::periodType() === Institution::PERIOD_WEEK_BLOCKS && $nextYearStart === null,
        ];
    }

    /**
     * @param  list<array{starts_on:string,ends_on:string}>  $periods
     * @return array{0:?Period,1:?Period}
     */
    private function neighbours(string $academicYear, array $periods): array
    {
        $firstStart = $periods[0]['starts_on'];
        $lastEnd = $periods[count($periods) - 1]['ends_on'];

        $previousYearLast = Period::query()
            ->where('academic_year', '!=', $academicYear)
            ->whereDate('starts_on', '<', $firstStart)
            ->orderByDesc('ends_on')
            ->first();

        $nextYearFirst = Period::query()
            ->where('academic_year', '!=', $academicYear)
            ->whereDate('starts_on', '>', $lastEnd)
            ->orderBy('starts_on')
            ->first();

        return [$previousYearLast, $nextYearFirst];
    }

    /**
     * Finding 14's distinction: a GAP against a neighbour is display-only (reconnaissance
     * finding 7 — the department sets its own start dates); a genuine day-COLLISION is not,
     * because it would mean one calendar day belongs to two different academic years'
     * schedules at once. Recomputes the same overlap condition
     * `PeriodGenerator::warningsAgainstNeighbours()` uses internally rather than parsing its
     * message strings, so the two can never drift apart on what counts as "real".
     *
     * @param  list<array{starts_on:string,ends_on:string,label:string}>  $periods
     */
    private function overlapAgainstNeighbours(array $periods, string $academicYear): ?string
    {
        [$previousYearLast, $nextYearFirst] = $this->neighbours($academicYear, $periods);

        $firstStart = $periods[0]['starts_on'];
        $lastEnd = $periods[count($periods) - 1]['ends_on'];

        if ($previousYearLast !== null) {
            $prevEnd = $previousYearLast->ends_on->format(Calendar::YMD);

            if ($firstStart <= $prevEnd) {
                return "This year's first period (\"{$periods[0]['label']}\", starting {$firstStart}) "
                    ."overlaps the previous year's last period (\"{$previousYearLast->label}\", "
                    ."ending {$prevEnd}). Academic year \"{$academicYear}\" was not generated.";
            }
        }

        if ($nextYearFirst !== null) {
            $nextStart = $nextYearFirst->starts_on->format(Calendar::YMD);

            if ($nextStart <= $lastEnd) {
                return "This year's last period (\"{$periods[count($periods) - 1]['label']}\", ending "
                    ."{$lastEnd}) overlaps the next year's first period (\"{$nextYearFirst->label}\", "
                    ."starting {$nextStart}). Academic year \"{$academicYear}\" was not generated.";
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function persistedYears(): array
    {
        return Period::query()
            ->orderBy('starts_on')
            ->get()
            ->groupBy('academic_year')
            ->map(fn ($rows, $year): array => [
                'academic_year' => $year,
                'kind' => $rows->first()->kind,
                'count' => $rows->count(),
                'starts_on' => Calendar::ymd($rows->first()->starts_on),
                'ends_on' => Calendar::ymd($rows->last()->ends_on),
            ])
            ->values()
            ->all();
    }
}
