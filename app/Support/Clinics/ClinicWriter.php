<?php

namespace App\Support\Clinics;

use App\Models\Clinic;
use App\Models\ClinicAttendee;
use App\Models\Level;
use App\Models\Person;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONE writer of `clinics` and `clinic_attendees` (Munawib CL-01/CL-02, P1e Decision B).
 * `tests/Feature/Build/ClinicWritersAreSingularTest.php` asserts at source level that nothing else
 * in app/, database/ or routes/ writes either table.
 *
 * IT IS NOT DEFENCE IN DEPTH OVER A DATABASE CONSTRAINT — IT IS THE CONSTRAINT. Three rules on
 * `clinic_attendees` are inexpressible on both engines this schema runs on, because a UNIQUE index
 * over nullable columns enforces nothing when NULLs compare distinct:
 *
 *   1. a row names EXACTLY ONE of `level_id` / `person_id` — never both, never neither;
 *   2. no level and no person appears twice within one clinic;
 *   3. the whole set is HOMOGENEOUS with its clinic's `attendee_mode` (`levels` admits only level
 *      rows, `named` only person rows, `rotators` none at all).
 *
 * The precedent is exact: `2026_08_14_120002` records that `person_levels`' overlap rule gets no
 * database constraint for the same engine reason, and that "the guarantee lives in
 * `App\Support\LevelAssignment`, which `PersonLevelsHaveOneWriterTest` proves is the only writer".
 *
 * EVERY REFUSAL THROWS, and throws BEFORE the transaction opens. A refusal written as a return
 * value inside a caller's transaction would not roll back with it (P1c-1 finding 12, ruling 35),
 * and a set validated halfway through writing is the "412 of 780 applied" state the bulk-operation
 * discipline refuses: validate the WHOLE set, then mutate, or mutate nothing.
 *
 * IT NEVER RESOLVES PEOPLE. Nothing here reads `master_rota_assignments`, computes who is on a
 * unit, or stores a roster. `clinic_attendees` holds a RULE and
 * `App\Support\Clinics\ClinicRoster::forDate()` (Task 3) is the one thing that turns that rule plus
 * the master rota into people, on a date, at read time. That is what keeps this table free of any
 * hook into `RotaAssignment`, `RotaFill` or `RotaImport` — a single-writer guard that passes while
 * the writer of table A writes table B is a guard that has stopped meaning anything.
 *
 * IT WRITES NO AUDIT ROW. The controller (Task 4) writes one per operator action, ids and field
 * names only — never a clinic's name, a unit's name or a person's name. Auditing from inside a
 * writer that callers wrap in their own transactions is the unwind problem
 * `ManagerScope::assertMayTarget()` already carries a docblock about.
 *
 * IT READS NO INSTITUTION. `institution_id` is provenance (D11) and arrives from the CALLER — the
 * `$request->user()?->institution_id` precedent `LevelController`, `PeriodController` and
 * `HolidayController` already set, the last of them naming in a comment the alternative it rejected.
 * Resolving the institution row inside this writer would be a second source for one fact, and would
 * also pull this file into `CalendarWritersFlushTest`'s needle set — where it would have to be
 * allow-listed for a column that has nothing to do with the calendar.
 *
 * That guard scans PROSE as well as code (deliberately, like `CalendarIsTheOnlyConverterTest`), and
 * this paragraph originally named the static it is rejecting, parentheses and all — which failed the
 * build on the rule's own statement (measured, 2026-08-11). It is spelled around rather than
 * deleted, because deleting the reasoning to satisfy a needle is the failure mode `RotaAccessTest`
 * built a comment-stripper to avoid: the guard is right that a file mentioning that call deserves a
 * look, and a reader who arrives here should still learn why the call is absent.
 */
final class ClinicWriter
{
    /**
     * @param  array{name?:string, weekday?:mixed, session?:mixed, location?:string|null, note?:string|null}  $attributes
     * @param  int|null  $institutionId  Provenance only (D11) — never a query filter, never a key.
     *
     * @throws InvalidArgumentException
     */
    public static function create(Unit $unit, array $attributes, ?int $institutionId = null): Clinic
    {
        self::assertOwns($unit);

        return Clinic::create(self::validated($attributes) + [
            'unit_id' => $unit->getKey(),
            'institution_id' => $institutionId,
            // A clinic starts on CL-02's default. Refining it is a second, explicit act through
            // `setAttendees()`, so `create()` can never leave a mode and an attendee set disagreeing.
            'attendee_mode' => Clinic::MODE_ROTATORS,
            'active' => true,
        ]);
    }

    /**
     * Rename, re-time, re-locate — and move to another clinic-owning unit.
     *
     * The unit arrives as a MODEL, not an id: resolving it is the caller's job, through route-model
     * binding or `Unit::findByCode()` (the `code` mutator normalises what is stored, not a query's
     * WHERE value). This writer never builds a lookup from user input.
     *
     * `attendee_mode` is NOT settable here. Changing the mode and changing the attached set are one
     * act, and `setAttendees()` is where they happen together — splitting them would admit a moment
     * where `levels` mode holds person rows.
     *
     * @param  array{name?:string, weekday?:mixed, session?:mixed, location?:string|null, note?:string|null}  $attributes
     *
     * @throws InvalidArgumentException
     */
    public static function update(Clinic $clinic, Unit $unit, array $attributes): Clinic
    {
        self::assertOwns($unit);

        $clinic->fill(self::validated($attributes) + ['unit_id' => $unit->getKey()]);
        $clinic->save();

        return $clinic;
    }

    /**
     * UN-04's "hides forward, never deletes history", applied to a clinic. There is no delete path
     * — not here and not on the controller.
     *
     * Reactivating is checked against the owning unit as it stands NOW: a clinic cannot be revived
     * onto a unit that has since been retired or has stopped owning clinics, which would otherwise
     * put a live clinic on a unit the clinics screen does not even offer.
     *
     * @throws InvalidArgumentException
     */
    public static function setActive(Clinic $clinic, bool $active): Clinic
    {
        if ($active) {
            $unit = $clinic->unit()->first();

            if (! $unit instanceof Unit) {
                throw new InvalidArgumentException('This clinic has no owning unit and cannot be reactivated.');
            }

            self::assertOwns($unit);
        }

        $clinic->fill(['active' => $active]);
        $clinic->save();

        return $clinic;
    }

    /**
     * CL-02's refinement, set WHOLE. The mode and the rows move together, always.
     *
     * REPLACE, NEVER APPEND — `RotaAssignment::split()`'s semantics and for its reason: a second
     * save of the same form, or a re-run of a seeder, must converge instead of duplicating. The
     * delete and the inserts share one transaction, so a clinic is never briefly ruleless.
     *
     * @param  string  $mode  One of `Clinic::ATTENDEE_MODES`.
     * @param  list<array{level_id?:int|null, person_id?:int|null}>  $attendees  Empty in `rotators`
     *                                                                           mode, which is the
     *                                                                           only mode that
     *                                                                           accepts an empty set.
     *
     * @throws InvalidArgumentException
     */
    public static function setAttendees(Clinic $clinic, string $mode, array $attendees = []): Clinic
    {
        if (! array_key_exists($mode, Clinic::ATTENDEE_MODES)) {
            throw new InvalidArgumentException(
                "[{$mode}] is not an attendee mode. Offered: ".implode(', ', array_keys(Clinic::ATTENDEE_MODES)).'.'
            );
        }

        // The whole set is checked before anything is written — a refusal must leave the clinic
        // exactly as it was, not half-refined.
        $rows = self::normaliseAttendees($mode, $attendees);

        DB::transaction(function () use ($clinic, $mode, $rows): void {
            $clinic->attendees()->delete();

            foreach ($rows as $row) {
                ClinicAttendee::create($row + ['clinic_id' => $clinic->getKey()]);
            }

            $clinic->fill(['attendee_mode' => $mode]);
            $clinic->save();
        });

        return $clinic;
    }

    /**
     * D2, held: a clinic's owning unit is a `units` ROW and the question is asked of the COLUMN.
     * Never a code list, never a `match` on 'WARD'. Owner Decision B (P1b) makes WARD the sole
     * clinic owner at QCH today; a fifth department that ticks `clinic_owner` gets clinics here
     * with no change to this file.
     *
     * @throws InvalidArgumentException
     */
    private static function assertOwns(Unit $unit): void
    {
        if (! $unit->clinic_owner) {
            throw new InvalidArgumentException(
                "Unit [{$unit->code}] does not own clinics. Tick \"owns clinics\" on Admin → "
                .'Structure → Units first.'
            );
        }

        if (! $unit->active) {
            throw new InvalidArgumentException(
                "Unit [{$unit->code}] is retired. A clinic on a retired unit appears on no map and "
                .'is coverage nobody can see.'
            );
        }
    }

    /**
     * The four CL-01 fields a caller may set, validated once for `create()` and `update()` — one
     * definition, two consumers, never re-typed (the `PeriodGenerator::assertMonthAligned()`
     * shape).
     *
     * `name`, `location` and `note` are stored VERBATIM. They are plain text, exactly like
     * `handovers.extra_fields`, and are never purified server-side: a clinic legitimately called
     * "ENT <> Audiology" must survive the round trip byte-identical, and escaping is the renderer's
     * job (`{{ }}` / `:value`, never `v-html`). Trimming is not purifying — it removes accidental
     * whitespace and changes no character the operator meant to type.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{name:string, weekday:int, session:string, location:string|null, note:string|null}
     *
     * @throws InvalidArgumentException
     */
    private static function validated(array $attributes): array
    {
        $name = is_string($attributes['name'] ?? null) ? trim($attributes['name']) : '';

        if ($name === '') {
            throw new InvalidArgumentException('A clinic needs a name.');
        }

        $weekday = $attributes['weekday'] ?? null;

        // ISO-8601, Monday = 1 … Sunday = 7. ZERO is the case worth naming: it is exactly what
        // Carbon's `dayOfWeek` calls Sunday, and admitting it would make this column a second
        // numbering scheme silently — every clinic a day out, with no error anywhere.
        if (! is_int($weekday) && ! (is_string($weekday) && ctype_digit($weekday))) {
            throw new InvalidArgumentException('A clinic\'s weekday must be an ISO-8601 day number, Monday = 1 … Sunday = 7.');
        }

        $weekday = (int) $weekday;

        if ($weekday < 1 || $weekday > 7) {
            throw new InvalidArgumentException(
                "[{$weekday}] is not an ISO-8601 weekday. Monday = 1 … Sunday = 7 — the same numbering "
                .'Calendar::weekendDays() and Calendar::weekdayColumns() use. Carbon\'s dayOfWeek '
                .'(Sunday = 0) is a different scheme and is never stored here.'
            );
        }

        $session = $attributes['session'] ?? null;

        // Offered and validated from ONE list (D9): `Clinic::SESSIONS` is what the picker renders
        // and what this rule reads, so the two cannot drift into two predicates.
        if (! is_string($session) || ! array_key_exists($session, Clinic::SESSIONS)) {
            throw new InvalidArgumentException(
                'A clinic runs in one of these sessions: '.implode(', ', array_keys(Clinic::SESSIONS)).'.'
            );
        }

        $location = $attributes['location'] ?? null;
        $note = $attributes['note'] ?? null;

        return [
            'name' => $name,
            'weekday' => $weekday,
            'session' => $session,
            'location' => is_string($location) && trim($location) !== '' ? trim($location) : null,
            'note' => is_string($note) && trim($note) !== '' ? trim($note) : null,
        ];
    }

    /**
     * Everything the database cannot say, said here — over the WHOLE set, before a single row is
     * written.
     *
     * @param  list<array{level_id?:int|null, person_id?:int|null}>  $attendees
     * @return list<array{level_id:int|null, person_id:int|null}>
     *
     * @throws InvalidArgumentException
     */
    private static function normaliseAttendees(string $mode, array $attendees): array
    {
        if ($mode === Clinic::MODE_ROTATORS) {
            if ($attendees !== []) {
                // A contradiction, not a request to ignore the set: `rotators` means "everyone the
                // rota has on this unit", and a leftover refinement row would be a rule nothing
                // reads and everything could start reading.
                throw new InvalidArgumentException(
                    'The "'.Clinic::ATTENDEE_MODES[Clinic::MODE_ROTATORS].'" mode takes no attendee '
                    .'rules. Choose a different mode, or send none.'
                );
            }

            return [];
        }

        $wantsLevels = $mode === Clinic::MODE_LEVELS;
        $column = $wantsLevels ? 'level_id' : 'person_id';
        $other = $wantsLevels ? 'person_id' : 'level_id';

        $ids = [];

        foreach ($attendees as $index => $row) {
            $mine = $row[$column] ?? null;
            $theirs = $row[$other] ?? null;

            // EXACTLY ONE OF. Both shapes below satisfy every unique index either engine can
            // express, which is why they are refused in code or not at all.
            if ($theirs !== null) {
                throw new InvalidArgumentException(
                    'Row '.($index + 1)." names a {$other} while this clinic refines by "
                    .($wantsLevels ? 'training level' : 'named people')
                    .'. A rule names exactly one of a level or a person.'
                );
            }

            if (! is_int($mine) && ! (is_string($mine) && ctype_digit($mine))) {
                throw new InvalidArgumentException(
                    'Row '.($index + 1)." names no {$column}. A rule names exactly one of a level or "
                    .'a person — never neither.'
                );
            }

            $mine = (int) $mine;

            if (in_array($mine, $ids, true)) {
                throw new InvalidArgumentException(
                    ($wantsLevels ? 'A training level' : 'A person')
                    .' is attached to this clinic twice. Each may appear once.'
                );
            }

            $ids[] = $mine;
        }

        if ($ids === []) {
            throw new InvalidArgumentException(
                'This mode needs at least one '.($wantsLevels ? 'training level' : 'person')
                .'. To attach everyone rotating on the unit, choose "'
                .Clinic::ATTENDEE_MODES[Clinic::MODE_ROTATORS].'".'
            );
        }

        // Refuse an id that names nothing, rather than letting the foreign key surface it as a
        // raw 500 from inside the caller's transaction. One query per set, not per row.
        $found = $wantsLevels
            ? Level::query()->whereIn('id', $ids)->pluck('id')->all()
            : Person::query()->whereIn('id', $ids)->pluck('id')->all();

        $missing = array_values(array_diff($ids, array_map('intval', $found)));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                ($wantsLevels ? 'These training levels do not exist: ' : 'These people do not exist: ')
                .implode(', ', $missing).'.'
            );
        }

        // VARIABLE KEYS, not two literal branches, and that is not a style preference. Spelling the
        // person column as an array key with a null literal beside it is a needle in
        // `AccountLinkHasOneWriterTest`, whose subject is `users.person_id`: an active-but-unbound
        // account is nameless and positionless on every screen with no error at all, so
        // `App\Support\AccountUnbind` is the one writer of that link and nothing else may null the
        // column. `clinic_attendees.person_id` is a different column on a different table that
        // merely shares a name — and writing it the obvious way failed that build (measured,
        // 2026-08-11), which is the guard behaving correctly at the only precision a substring scan
        // has. Do not "clarify" this back into two literal arrays; that reintroduces the match.
        return array_map(
            fn (int $id): array => [$column => $id, $other => null],
            $ids
        );
    }
}
