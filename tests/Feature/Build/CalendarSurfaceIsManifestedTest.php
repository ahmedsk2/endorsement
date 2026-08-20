<?php

namespace Tests\Feature\Build;

use App\Support\Calendar;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * THE DRIFT THE GOLDEN FIXTURE CANNOT CLOSE, converted into a forced decision.
 *
 * `tests/fixtures/calendar/golden.json` is the contract between `App\Support\Calendar` and the TS
 * mirror, asserted from both sides. Its stated residual — recorded when the mirror shipped at P2
 * Tasks 5/6 — is that the two can still drift on a **new** `Calendar` method, because `golden.json`
 * only grows when somebody remembers to grow it. Task 5's coverage manifest closes that for fixture
 * BLOCKS and cannot close it for the PHP surface, and the plan proposed this test for *"Task 22 or
 * early P3, whichever touches `Calendar` first"*. Task 22 touched it, adding three public methods —
 * which is exactly the shape the residual describes.
 *
 * IT DOES NOT WRITE THE ASSERTION FOR YOU, and that is the whole design. It converts SILENT DRIFT
 * into a FORCED DECISION: a new public method on the one date converter fails the build until
 * somebody classifies it as mirrored or as server-side-only WITH THE REASON, which is the same
 * property `UnitMerge::REFERENCES` buys for a table nothing re-points — *an entry is a decision, not
 * documentation*.
 *
 * ## BOTH DIRECTIONS, AND THE SECOND ONE IS NOT DECORATIVE
 *
 * A one-way check (every method is classified) passes on a manifest that has grown stale entries for
 * methods long deleted, and a stale manifest is how a list stops being read. So a name on either
 * list that `Calendar` no longer exposes fails too.
 *
 * And the MIRRORED half carries its counterpart's name in the package, checked against the
 * package's own source. Without that, "this one is mirrored" is a claim nobody verifies — the
 * mirror could lose `weekOf` tomorrow and this list would still say it has it. With it, the two
 * halves of the classification are each answerable against something real.
 *
 * ## WHY SO MUCH OF IT IS SERVER-SIDE ONLY
 *
 * That is not a gap; it is P2's Decisions B and C working. The engine holds no instant, no timezone
 * and no Hijri; holidays arrive already resolved to Gregorian dates, week windows arrive as
 * `periods[].weeks`, the day vector arrives precomputed, and every department-varying fact —
 * the weekend days, the week start — is a PARAMETER of the mirror rather than a literal in it. Nine
 * mirrored names against two dozen server-side ones is the measurement of how small Decisions B and
 * C succeeded in making the second implementation.
 */
class CalendarSurfaceIsManifestedTest extends TestCase
{
    /**
     * `Calendar`'s public static method => the name the TS mirror exports for it.
     *
     * Every one of these is a genuine second implementation of one fact, and `golden.json` is what
     * holds the two in step. Adding to this list means adding to that fixture.
     *
     * @var array<string, string>
     */
    private const MIRRORED = [
        'parse' => 'parseYmd',
        'tryParse' => 'tryParseYmd',
        'addDays' => 'addDays',
        'datesBetween' => 'datesBetween',
        'isoWeekday' => 'isoWeekday',
        'isWeekend' => 'isWeekend',
        'dayType' => 'dayType',
        'weekdayColumns' => 'weekdayColumns',
        'weekOf' => 'weekOf',
    ];

    /**
     * `Calendar`'s public static method => why the mirror does NOT implement it.
     *
     * @var array<string, string>
     */
    private const SERVER_SIDE_ONLY = [
        'flush' => 'Memoization control for a process that holds database-backed settings. The engine holds no state to drop.',
        'timezone' => 'Decision B: the engine holds no timezone. `EvaluationContext.timezone` is provenance and fixture identity only, and `contract.test.ts` asserts no module under `src/` so much as reads it.',
        'today' => 'Decision B: "today" ARRIVES in the context and is never computed. An engine that computed one would have acquired an instant.',
        'now' => 'As `today`, and worse — an instant rather than a civil date.',
        'todayYmd' => 'As `today`. This is the value the context builder passes in.',
        'ymd' => 'A FORMATTER from a Carbon to Y-m-d. The engine\'s date type IS Y-m-d (a branded string), so there is nothing to format and no Carbon to format it from.',
        'hijriEnabled' => 'Decision C and owner decision AA: no Hijri in the browser.',
        'hijriOffsetDays' => 'As `hijriEnabled` — the department\'s calibration is applied server-side, before anything reaches the engine.',
        'hijri' => 'Decision C: ICU in the browser is not guaranteed to agree with PHP\'s, and `Intl.DateTimeFormat` is a forbidden needle besides.',
        'hijriLabel' => 'As `hijri`, plus AR-07: the month names live in `lang/en/calendar.php`, never in the package.',
        'holidaysOn' => 'Holidays are resolved server-side into Gregorian dates and arrive in the day vector. A mirror would need the rules table and the Hijri conversion.',
        'holidayOccurrencesOn' => 'As `holidaysOn`. Its extra fact — the anchor\'s own-calendar year (owner decision W) — arrives as `days[].holidays[].year` for exactly this reason.',
        'isHoliday' => 'As `holidaysOn`. The engine reads `days[].dayType`, which already says so.',
        'dayFacts' => 'The whole per-date vector is precomputed server-side (P2 Task 1 finding 6): `dayType()` is query-free but CPU-expensive, and re-asking it per type made 30 days cost 203 ms instead of 24 ms.',
        'label' => 'Dual-dated DISPLAY labels. P1a Decision A gives the client no date formatting at all, and the engine renders nothing — it returns findings, and the sentences come from the message table.',
        'weekendDays' => 'Owner decision X: a department-varying fact is a PARAMETER of the mirror, never a literal in it. It arrives as `EvaluationContext.weekendDays`.',
        'weekStartIsoDay' => 'As `weekendDays`, and it is derived from them by a rule (the day after the last weekend day, wrapping) that only the institution row can answer.',
        'weekdayLabel' => 'AR-07: the names live in `lang/en/calendar.php`. `CalendarIsTheOnlyConverterTest`\'s quoted-weekday scan over `packages/` forbids one appearing in the package at all.',
        'weeksIn' => 'Owner decision O: `golden.json` has ZERO coverage of the clipped bounds, so a mirror implementation would be an unasserted second definition of a per-department fact. Week windows arrive as `periods[].weeks`.',
        'periodType' => 'Reads the institution row. Periods arrive in the context.',
        'blockWeeks' => 'As `periodType` — and block 13 being five weeks is exactly the kind of per-department fact the context carries rather than the package deriving.',
        'academicYearStart' => 'As `periodType`.',
        'periodFor' => 'Queries the periods table. The engine reads `days[].periodKey`, resolved once by the context builder.',
        'periodsForYear' => 'As `periodFor`.',
    ];

    /** @return list<string> */
    private function publicStatics(): array
    {
        $names = [];

        foreach ((new ReflectionClass(Calendar::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() && $method->getDeclaringClass()->getName() === Calendar::class) {
                $names[] = $method->getName();
            }
        }

        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function manifested(): array
    {
        $names = array_merge(array_keys(self::MIRRORED), array_keys(self::SERVER_SIDE_ONLY));
        sort($names);

        return $names;
    }

    /**
     * Both directions in one comparison. A new public method is unclassified and fails; a name left
     * behind by a deletion is stale and fails.
     */
    public function test_every_public_calendar_method_is_classified(): void
    {
        $this->assertSame(
            $this->manifested(),
            $this->publicStatics(),
            "App\\Support\\Calendar is the ONE date converter, and packages/engine mirrors part of\n"
            ."it. `golden.json` holds the mirrored part in step but only grows when somebody\n"
            ."remembers to grow it, so a NEW public method here is silent drift by default. Classify\n"
            ."it: MIRRORED (and add cases to tests/fixtures/calendar/golden.json), or\n"
            ."SERVER_SIDE_ONLY with the reason the engine does not need it. A name on the manifest\n"
            ."that no longer exists is stale and must be removed — a manifest people stop trusting\n"
            .'is a manifest people stop reading.'
        );
    }

    /**
     * The MIRRORED half, answered against the package rather than against itself. Without this the
     * classification is a claim nobody checks: the mirror could lose `weekOf` and this list would
     * go on saying it has it.
     */
    public function test_every_mirrored_method_has_its_counterpart_in_the_package(): void
    {
        $source = '';

        foreach (glob(base_path('packages/engine/src/calendar/*.ts')) ?: [] as $path) {
            $source .= (string) file_get_contents($path);
        }

        $this->assertStringContainsString(
            'export function',
            $source,
            'The calendar mirror\'s source was not found or exports nothing — this check would then '
            .'pass by matching an empty string against every name below.'
        );

        $missing = [];

        foreach (self::MIRRORED as $php => $ts) {
            if (! str_contains($source, 'export function '.$ts.'(')) {
                $missing[] = "Calendar::{$php}() claims a mirror counterpart `{$ts}`, which packages/engine/src/calendar does not export";
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing));
    }

    /**
     * Non-vacuity. A reflection that returned nothing — a renamed class, a changed filter — would
     * make the comparison above `[] === []` and pass on a tree with no calendar in it at all.
     */
    public function test_the_reflection_reads_a_real_surface(): void
    {
        $names = $this->publicStatics();

        $this->assertGreaterThan(20, count($names), 'The reflection found almost no public methods on Calendar.');

        foreach (['parse', 'dayType', 'weeksIn', 'hijri'] as $expected) {
            $this->assertContains($expected, $names, "The reflection missed Calendar::{$expected}().");
        }
    }
}
