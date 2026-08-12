<?php

namespace App\Support\Setup;

use App\Models\Clinic;
use App\Models\Holiday;
use App\Models\Institution;
use App\Models\Invitation;
use App\Models\Level;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Support\Calendar;
use App\Support\Demo\DemoLedger;
use Carbon\CarbonImmutable;

/**
 * Munawib ST-01 / ST-02: how far this department is from configured — ASKED of the data, every
 * time, and stored nowhere. P1e Decision D.
 *
 * NO TABLE, NO COLUMN, NO `app_settings` KEY, NO SESSION KEY. `steps()` is a pure projection
 * over counting queries; calling it twice writes nothing and changes nothing.
 * `DepartmentSetupTest::test_asking_writes_nothing_anywhere` snapshots every table in the schema
 * around a call and pins that.
 *
 * SERVER-SIDE WIZARD STATE WAS CONSIDERED AND REJECTED, and this codebase has made the same
 * call three times already: `Person::hasAccount()` is a join and never a `person_status` enum;
 * `App\Support\Invitations\InvitationStatus` is a derived projection and never a stored column;
 * the master rota has no `status` column at all. This is the fourth. A stored counter costs:
 *
 *  - **A second definition of one fact.** "Step 4 is done" and "a `periods` row covers a date
 *    from today on" are the same fact stored twice, and they drift the first time an
 *    administrator deletes a year from Structure → Periods.
 *  - **A migration for a department that is already configured.** QCH is configured today. A
 *    stored counter shows a live, working department at step 0 and invites an administrator to
 *    redo everything; a derived checklist shows it complete with no backfill.
 *  - **A half-abandoned wizard becomes something to clean up.** With no state, abandonment is a
 *    non-event: the administrator closes the tab and the department is exactly as configured as
 *    it was. Reopening the screen recomputes. Nothing to resume, expire or garbage-collect.
 *
 * TWO KINDS OF STEP, AND CONFLATING THEM IS HOW A CHECKLIST STARTS LYING.
 *
 *  - **REQUIRED** — derivable and binary. Its `done` is a query.
 *  - **REVIEW** — `profile`, `calendar`, `holidays`, and they are **never** reported done. A
 *    department whose calendar is entirely default is *configured* and quite possibly *wrong*:
 *    ST-01's own example is the Hijri offset, which defaults to 0 where the QCH prototype needed
 *    -1, and no query distinguishes a reviewed zero from an unexamined one. Zero holiday rules
 *    is the same shape. So a REVIEW step renders its CURRENT VALUES inline — review is possible
 *    without leaving the page — and is labelled for review rather than shown as an unticked box.
 *    Inventing a `reviewed_at` flag to make them tickable is the stored state this class exists
 *    to refuse, in miniature.
 *
 * A LEDGERED ROW IS NOT CONFIGURATION (P1e-2 review, finding 3). The demo department may be
 * created on the LIVE instance (owner ruling, 2026-08-11), and it lands one unit, five people, two
 * periods and a clinic — so pressing that button used to take this checklist from two required
 * steps to five on a department where nothing real had been configured at all, which is the exact
 * lie in the exact direction nobody re-checks. Every step whose count the demo can move —
 * `units`, `periods`, `roster`, `clinics`, and the clinic step's *"does any unit run clinics"*
 * question — therefore asks through `DemoLedger::notLedgered()`, because `demo_rows` is the only
 * place in this schema with the authority to say a row is not real. `levels`, `holidays` and
 * `invitations` are untouched: the demo reads the ladder rather than writing it, and creates
 * neither of the other two. IT COSTS NO QUERY — `notLedgered()` is a subquery, chosen so the
 * measured ten-query bound below still holds exactly.
 *
 * `blocked_by` IS ADVISORY AND NEVER BECOMES THE GATE. It names an unmet prerequisite by reading
 * the same predicate the target screen's own server-side rule already enforces; the target still
 * refuses on its own, and `DepartmentSetupTest::test_the_checklist_never_becomes_the_gate` asks a
 * blocked step's screen directly and asserts it answers for itself. A checklist that authorizes
 * is a second authorization boundary, and two boundaries drift.
 *
 * ST-03's LAUNCH PRESETS CANNOT SHIP IN ANY SUBSET, so no step names one. Both of its presets
 * are duty-slot and coverage-template presets, and neither of those tables — nor the duty-hour
 * rules that read them — exists here as a table, a class or a concept. A "Stage-1 subset" of a
 * slot preset is the empty set. Those items are STATED in `later` (prose, a stage label, no
 * route, no `done` key) rather than rendered as permanently unticked boxes an administrator will
 * try to click.
 *
 * CALENDAR MEMO: this class only READS the institution row, so it is on
 * `CalendarWritersFlushTest::ALLOW_LIST` beside `CreateAdmin` and `InstanceShow` — it writes
 * nothing, and there is nothing here for a flush to clear.
 */
class DepartmentSetup
{
    /** Derivable and binary: `done` is a query. */
    public const KIND_REQUIRED = 'required';

    /** Never reported done, deliberately: a default is indistinguishable from a reviewed one. */
    public const KIND_REVIEW = 'review';

    /**
     * The whole checklist, derived. Ten queries on a configured department, measured.
     *
     * @return array{steps: list<array<string, mixed>>, later: list<array<string, mixed>>}
     */
    public static function steps(): array
    {
        $institution = Institution::current();

        // Every count below is ONE query and buys the summary line as well as the tick. An
        // `exists()` would cost the same query and leave a REVIEW-less REQUIRED step saying
        // "done" with nothing behind it.
        $levels = Level::query()->active()->count();
        $units = DemoLedger::notLedgered(Unit::query()->active(), 'units')->count();
        $holidays = Holiday::query()->where('active', true)->count();

        // "Still to run", not "exists": a department whose only generated year ended in a
        // previous academic year is not configured for this one, and a checklist that called
        // that done would be lying in the direction nobody re-checks. `whereDate()` rather than
        // a string comparison because `ends_on` is `date`-cast, and against a plain 'Y-m-d'
        // string such a column compares wrongly on the BOUNDARY DAY — here, a period ending
        // exactly today. Honestly stated: '>=' happens to be one of the two operators a bare
        // comparison gets right (measured on SQLite; '=' , '<=' and '>' are the three it gets
        // wrong), so this call site would survive being written bare. It is written the same way
        // as every other date read anyway, because the rule is stated over the column and not
        // over the operator — a reader should not have to re-derive which comparisons are safe,
        // and the safe set is not a fact anybody should be relying on. The caveat
        // MasterRotaAssignment::booted() and Vacation::scopeIntersecting() each already carry;
        // EndorsementController::updateSignoff() is the canonical statement, including the note
        // that these comments used to name the wrong engine.
        $periods = DemoLedger::notLedgered(
            Period::query()->whereDate('ends_on', '>=', Calendar::todayYmd()),
            'periods',
        )->count();

        // The roster of record, excluding administrators: an instance with one bootstrap admin
        // and nobody else has no roster, whatever the `people` count says.
        $roster = DemoLedger::notLedgered(
            Person::query()->where('active', true)->where('position', '!=', 0),
            'people',
        )->count();

        $clinics = DemoLedger::notLedgered(Clinic::query()->active(), 'clinics')->count();

        // The demo unit is a clinic owner, so it is excluded here too — otherwise a department
        // whose own units run no clinics would read "no clinics defined on the units that run
        // them" on the strength of a demo unit nobody configured.
        $clinicOwners = DemoLedger::notLedgered(
            Unit::query()->active()->where('clinic_owner', true),
            'units',
        )->exists();

        // "CAN ANYBODY GET IN" — still redeemable, OR already redeemed (P1e-2 review, finding 7).
        // A bare `count()` here read a link issued and immediately REVOKED, or one that quietly
        // aged past its seven days with nobody claiming it, as the last box on the wizard ticked:
        // the state where the door is shut wearing the state where it is open. The open half is
        // the model's own `open()` scope, which `Invitation::redeemable()` also resolves through,
        // so the predicate that decides whether a token still works and the one this step reports
        // cannot drift. The accepted half is not decoration — an accepted invitation is NOT open,
        // and a department whose whole roster has claimed its links is the most configured a
        // department gets, so an `open()`-only step would UN-tick at exactly the wrong moment.
        // One query, one table: `invitations` is the only path from a roster row to an account.
        $invitations = Invitation::query()
            ->where(fn ($open) => $open->open())
            ->orWhereNotNull('accepted_at')
            ->count();

        $yearStart = Calendar::academicYearStart();

        return [
            'steps' => [
                [
                    'key' => 'profile',
                    'title' => 'Department profile',
                    'kind' => self::KIND_REVIEW,
                    'route' => 'admin.structure.department',
                    'done' => false,
                    'blocked_by' => null,
                    'summary' => $institution === null
                        ? 'This deployment has no single active institution.'
                        : $institution->name.' ('.$institution->code.')',
                ],
                [
                    'key' => 'calendar',
                    'title' => 'Calendar',
                    'kind' => self::KIND_REVIEW,
                    'route' => 'admin.structure.calendar',
                    'done' => false,
                    'blocked_by' => null,
                    'summary' => self::calendarSummary($yearStart),
                ],
                [
                    'key' => 'levels',
                    'title' => 'Training levels',
                    'kind' => self::KIND_REQUIRED,
                    'route' => 'admin.structure.levels',
                    'done' => $levels > 0,
                    'blocked_by' => null,
                    'summary' => $levels > 0
                        ? $levels.' active training levels.'
                        : 'No active training level. A person\'s level history is recorded against these.',
                ],
                [
                    'key' => 'units',
                    'title' => 'Units',
                    'kind' => self::KIND_REQUIRED,
                    'route' => 'admin.structure.units',
                    'done' => $units > 0,
                    'blocked_by' => null,
                    'summary' => $units > 0 ? $units.' active units.' : 'No active unit.',
                ],
                [
                    'key' => 'holidays',
                    'title' => 'Holidays',
                    'kind' => self::KIND_REVIEW,
                    'route' => 'admin.structure.holidays',
                    'done' => false,
                    'blocked_by' => null,
                    // Zero is a legitimate state, so it reads as a VALUE rather than as an
                    // omission — which is exactly why this step can never be ticked.
                    'summary' => $holidays.' active holiday rules. A department that observes '
                        .'none is configured, not unfinished.',
                ],
                [
                    'key' => 'periods',
                    'title' => 'Rota periods',
                    'kind' => self::KIND_REQUIRED,
                    'route' => 'admin.structure.periods',
                    'done' => $periods > 0,
                    'blocked_by' => $yearStart === null
                        ? 'The academic year start is not set yet (Structure → Calendar).'
                        : null,
                    'summary' => $periods > 0
                        ? $periods.' rota periods run to today or later.'
                        : 'No generated period covers today or any date after it.',
                ],
                [
                    'key' => 'roster',
                    'title' => 'Roster',
                    'kind' => self::KIND_REQUIRED,
                    'route' => 'admin.roster-import',
                    'done' => $roster > 0,
                    'blocked_by' => $levels > 0
                        ? null
                        : 'An import maps a training level on every row, and no level is active.',
                    'summary' => $roster > 0
                        ? $roster.' people on the roster.'
                        : 'Nobody on the roster yet. Import a file, or add people one at a time '
                            .'under Administration → People.',
                ],
                [
                    'key' => 'clinics',
                    'title' => 'Clinics',
                    'kind' => self::KIND_REQUIRED,
                    'route' => 'admin.structure.clinics',
                    // Satisfied when no unit runs clinics at all: a department without an
                    // outpatient week is a valid department, not an unfinished one. Which is
                    // also why this step carries no `blocked_by` — the state the plan's table
                    // named as its block ("no clinic-owning unit") is the state that SATISFIES
                    // it, so a live block here is unreachable by construction.
                    'done' => $clinics > 0 || ! $clinicOwners,
                    'blocked_by' => null,
                    'summary' => match (true) {
                        $clinics > 0 => $clinics.' active clinics.',
                        $clinicOwners => 'No clinics defined on the units that run them.',
                        default => 'No unit runs clinics, so there is nothing to define here.',
                    },
                ],
                [
                    'key' => 'invitations',
                    'title' => 'Invitations',
                    'kind' => self::KIND_REQUIRED,
                    'route' => 'admin.people',
                    'done' => $invitations > 0,
                    'blocked_by' => $roster > 0 ? null : 'There is nobody on the roster to invite.',
                    // The summary says what was counted. "N invitations issued" over a set that
                    // excludes the revoked and the expired would be a number nobody could
                    // reconcile with the invitations screen.
                    'summary' => $invitations > 0
                        ? $invitations.' invitations are live or have been claimed.'
                        : 'No live or claimed invitation — the only path from a roster entry to an '
                            .'account. A revoked or expired one leaves nobody able to get in.',
                ],
            ],
            // STATED, never rendered as a step: no route, no `done`, nothing to click or tick.
            'later' => [
                [
                    'key' => 'slots',
                    'title' => 'Duty slots and coverage templates',
                    'stage' => 'Stage 2',
                    'summary' => 'Who is on duty, in what shape of day, and how a week of it is '
                        .'templated. The tables behind this do not exist yet; when they arrive, '
                        .'the launch presets ST-01 names arrive with them.',
                ],
                [
                    'key' => 'conditions',
                    'title' => 'Duty-hour rules',
                    'stage' => 'Stage 3',
                    'summary' => 'The rules a published roster can violate — rest after a night, '
                        .'consecutive days, clinic the morning after call — and how loudly. They '
                        .'read the clinics and the rota this stage already stores.',
                ],
            ],
        ];
    }

    /**
     * The calendar's CURRENT VALUES, rendered as one line so review is possible without leaving
     * the checklist. Every value comes from `App\Support\Calendar` — including the weekday
     * names, which are a vocabulary lookup there and are never assembled here or on the client.
     */
    private static function calendarSummary(?CarbonImmutable $yearStart): string
    {
        $weekend = array_map(Calendar::weekdayLabel(...), Calendar::weekendDays());

        $parts = [
            Calendar::hijriEnabled()
                ? 'Hijri dates shown, offset '.Calendar::hijriOffsetDays()
                : 'Hijri dates hidden',
            'weekend '.($weekend === [] ? 'not set' : implode(' and ', $weekend)),
            Institution::PERIOD_TYPES[Calendar::periodType()] ?? Calendar::periodType(),
            'year starts '.($yearStart === null ? 'not set' : Calendar::ymd($yearStart)),
            'clocks on '.Calendar::timezone(),
        ];

        return implode(' · ', $parts).'.';
    }
}
