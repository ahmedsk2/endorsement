<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Clinic;
use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\MasterRotaAssignment;
use App\Models\Unit;
use App\Models\UnitFieldDefinition;
use App\Models\User;
use App\Support\Clinics\ClinicWriter;
use App\Support\Rota\RotaAssignment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Munawib UN-01's merge. ONE definition of what a merge does, shared by the preview screen and
 * the committing endpoint — a preview computed one way and a commit performed another is the
 * drift `SignoffPickers` and `AuditChain::canonical()` both carry docblocks about.
 *
 * IT COVERS EVERY FOREIGN KEY THAT POINTS AT `units`, AND {@see REFERENCES} IS THE PROOF. For four
 * slices it covered four of the seven and stranded three (design §14 item 23): a retired unit kept
 * its rota spans, its clinics and — worst, because nothing anywhere could repair it — its members'
 * push-reminder opt-ins, which silently stopped arriving. What was missing was never a bug inside
 * the writer but a cross-cutting obligation nothing checked: ADDING A `unit_id` COLUMN OBLIGES YOU
 * TO ANSWER THIS FILE. `UnitMergeCoversEveryUnitReferenceTest` now derives that obligation from the
 * live schema in both directions, so the eighth such column fails the build until somebody writes
 * down what a merge does with it.
 *
 * THE COLLISIONS, one verdict per table, because a merge that silently drops a row is the defect
 * class this codebase keeps paying for:
 *
 *  1. `handover_signoffs` — UNIQUE(unit_id, handover_date) (2026_07_24_130002:77). Two units each
 *     signed off on the same date cannot both re-point. Discovered in `plan()` and CONFIRMED BY A
 *     HUMAN, date by date, before `commit()` runs — never encountered as a 23000 mid-transaction.
 *     The source's header stays on the (retired) source; nothing signed is ever deleted.
 *  2. `unit_field_definitions` — UNIQUE(unit_id, key) (2026_08_09_120001:61). Refused outright by
 *     `conflictingFieldDefinitionKeys()` before any write: unlike a signoff date there is no
 *     `keep_target`-shaped answer to offer, so the merge stops and names the remedy.
 *  3. `reminder_preferences` — UNIQUE(user_id, unit_id). THE ONLY OTHER REAL ONE. An account opted
 *     in to both units cannot hold two rows, so the source's row is DROPPED. That loses nothing:
 *     the surviving row states the identical fact about the unit that survives. It gets no per-row
 *     confirmation because there is no second reading — this table is pure infrastructure by its
 *     own migration's words, and a kept-behind row would name a retired unit `endorsement:remind`
 *     never visits. The count is reported separately in the plan, on the screen and in the audit
 *     row rather than folded into the moved figure.
 *  4. `master_rota_assignments` — NONE POSSIBLE. The overlap rule is per (person, period) and
 *     unit-blind, so re-pointing `unit_id` cannot create an overlap the stored set did not already
 *     have; see `RotaAssignment::repointUnit()` for the split-across-both-units case.
 *  5. `clinics` — NONE POSSIBLE. The migration deliberately omits a unique key on `(unit_id,
 *     weekday, session)`; duplicates are a legitimate state and all of them move. The refusal is a
 *     different question, asked up front: the target must own clinics.
 *  6. `handovers`, `users.preferred_unit_id` — no constraint to violate; every row re-points.
 *
 * THE THREE LATE ARRIVALS MOVE TOGETHER OR NOT AT ALL, and a clinic-shaped fix alone would have
 * been worse than none. `ClinicRoster` answers "who is rotating on this unit" from
 * `master_rota_assignments` AT READ TIME, so a clinic re-pointed onto the target while its rota
 * spans stayed on the source is a clinic that resolves to nobody, with no error anywhere.
 *
 * IT IS NOT A SECOND WRITER OF ANYTHING. `master_rota_assignments` goes through
 * `RotaAssignment::repointUnit()` and `clinics` through `ClinicWriter::repointUnit()`, which is
 * what lets both single-writer guards keep scanning THIS file — each of them withdrew a needle
 * rather than allow-list it, on the argument that an exemption here would blind them where the next
 * offender arrives. That argument was right, so the exemption is still not taken.
 *
 * WHAT A MERGE NEVER DOES: delete a clinical row, delete a sign-off, or delete the source unit.
 * The source is retired (`active = false`) and stays in the database, because every audit row,
 * every handover revision and every future forensic question refers to it by id.
 */
final class UnitMerge
{
    /** The source's colliding signoff header stays on the (retired) source; nothing is lost. */
    public const KEEP_TARGET = 'keep_target';

    /** An explicit, named refusal to merge — distinct from simply not submitting. */
    public const ABORT = 'abort';

    /**
     * Every foreign key in the schema that points at `units`, and what a merge does with it.
     *
     * IT IS HAND-WRITTEN AND IT IS NOT TRUSTED — `DemoReferences::MAP`'s discipline, for the same
     * reason: a hand-written list of foreign keys goes stale the next time somebody writes a
     * migration, and a stale entry here is a table silently stranded on a retired unit.
     * `UnitMergeCoversEveryUnitReferenceTest` derives the real set from the LIVE SCHEMA and compares
     * it against this constant in both directions, so a key this map has never heard of fails the
     * build, and a map entry naming a key that no longer exists fails too.
     *
     * The sentences are the record of a DECISION, not documentation of code — that is why a table
     * whose answer is "nothing" would still belong here, spelled out, rather than omitted. There is
     * no such entry today; all seven move.
     *
     * @var array<string, string> `table.column` => what a merge does with it
     */
    public const REFERENCES = [
        'clinics.unit_id' => 'Re-pointed through ClinicWriter::repointUnit(). No unique key to '
            .'violate; the target must own clinics, checked before any write.',
        'handover_signoffs.unit_id' => 'Re-pointed, except on a date both units already signed — '
            .'that header stays on the retired source, confirmed date by date beforehand.',
        'handovers.unit_id' => 'Re-pointed whole, soft-deleted rows included.',
        'master_rota_assignments.unit_id' => 'Re-pointed through RotaAssignment::repointUnit(), row '
            .'by row so the containment and overlap guards run on each.',
        'reminder_preferences.unit_id' => 'Re-pointed, except where the account already holds the '
            .'target — that duplicate is dropped and counted separately.',
        'unit_field_definitions.unit_id' => 'Re-pointed whole; a key both units define refuses the '
            .'merge outright, before any write.',
        'users.preferred_unit_id' => 'Re-pointed, or the account lands on a unit nothing leads it '
            .'to and 404s on its own home page.',
    ];

    /**
     * What this merge would do. Read-only; touches no data.
     *
     * EVERY FIGURE COUNTS ROWS THAT ACTUALLY CHANGE, and the two that have an exception report it
     * as its own number rather than folding it in. `signoffs` excludes the listed `collisions` —
     * a colliding date's header still belongs to the source going in, so it is not counted as
     * moving — and `reminders` excludes `reminders_dropped`, the opt-ins the account already holds
     * on the target. A merge that reported one total for "moved and quietly deleted" would be the
     * silent-drop shape this whole file guards against.
     *
     * @return array{handovers:int, signoffs:int, field_definitions:int, preferred_unit_users:int,
     *               rota_assignments:int, clinics:int, reminders:int, reminders_dropped:int,
     *               collisions:list<string>}
     */
    public static function plan(Unit $source, Unit $target): array
    {
        $handovers = Handover::withTrashed()->where('unit_id', $source->getKey())->count();

        $sourceDates = HandoverSignoff::withTrashed()->where('unit_id', $source->getKey())
            ->get(['handover_date'])
            ->map(fn (HandoverSignoff $s): string => $s->handover_date->format('Y-m-d'));

        $targetDates = HandoverSignoff::withTrashed()->where('unit_id', $target->getKey())
            ->get(['handover_date'])
            ->map(fn (HandoverSignoff $s): string => $s->handover_date->format('Y-m-d'))
            ->flip();

        $collisions = $sourceDates
            ->filter(fn (string $date): bool => $targetDates->has($date))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $fieldDefinitions = UnitFieldDefinition::where('unit_id', $source->getKey())->count();
        $preferredUnitUsers = User::where('preferred_unit_id', $source->getKey())->count();

        // `Model::where(` rather than `Model::query()->where(`, and that is not style. Design §14
        // item 26 has a `::query()` needle queued for both single-writer guards; a READ spelled
        // that way would match it and force an allow-list entry for this file — the exact trap both
        // guards avoided when they withdrew `->update(['unit_id'` over it. `::where(` cannot be
        // mistaken for a write, and it is what the four counts above already use.
        $rotaAssignments = MasterRotaAssignment::where('unit_id', $source->getKey())->count();
        $clinics = Clinic::where('unit_id', $source->getKey())->count();
        $reminders = self::reminderOptIns($source, $target);

        return [
            'handovers' => $handovers,
            'signoffs' => $sourceDates->count() - count($collisions),
            'field_definitions' => $fieldDefinitions,
            'preferred_unit_users' => $preferredUnitUsers,
            'rota_assignments' => $rotaAssignments,
            'clinics' => $clinics,
            'reminders' => count($reminders['moving']),
            'reminders_dropped' => count($reminders['dropping']),
            'collisions' => $collisions,
        ];
    }

    /**
     * The source's reminder opt-ins, split by what UNIQUE(user_id, unit_id) allows.
     *
     * ONE DEFINITION, TWO CONSUMERS — `plan()` counts the two lists and `commit()` writes them, so
     * the preview cannot describe a different split from the one performed (the `SignoffPickers`
     * discipline, and `PeriodGenerator::assertMonthAligned()`'s shape).
     *
     * There is no `ReminderPreference` model and this reads the table directly, which is the honest
     * spelling rather than a gap: the opt-in is a pivot behind `User::reminderUnits()`, written
     * everywhere else through that relation's `sync()`, and inventing a model to re-point a pivot
     * would be a second way to name one row.
     *
     * @return array{moving: list<int>, dropping: list<int>} user ids
     */
    private static function reminderOptIns(Unit $source, Unit $target): array
    {
        $targetUsers = DB::table('reminder_preferences')
            ->where('unit_id', $target->getKey())
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        $moving = [];
        $dropping = [];

        foreach (DB::table('reminder_preferences')->where('unit_id', $source->getKey())->orderBy('id')->pluck('user_id') as $userId) {
            $userId = (int) $userId;

            if ($targetUsers->has($userId)) {
                $dropping[] = $userId;

                continue;
            }

            $moving[] = $userId;
        }

        return ['moving' => $moving, 'dropping' => $dropping];
    }

    /**
     * How many of the source's clinics the target cannot receive, because it does not own clinics.
     *
     * Surfaced the way `conflictingFieldDefinitionKeys()` is — a separate question the caller asks
     * BEFORE any write, so the operator reads a refusal naming the control that fixes it ("tick
     * *owns clinics* on the target") instead of `ClinicWriter` throwing from inside the transaction.
     * Zero whenever the source has no clinics at all, which is the ordinary case: `clinic_owner` is
     * opt-in and WARD is the only unit that holds it at QCH.
     */
    public static function clinicsTheTargetCannotOwn(Unit $source, Unit $target): int
    {
        if ($target->clinic_owner) {
            return 0;
        }

        return Clinic::where('unit_id', $source->getKey())->count();
    }

    /**
     * Custom field DEFINITION keys both units already use. There is no resolution for this
     * today — it is surfaced so the caller can refuse the merge before any write, not silently
     * offered a `keep_target`-style choice nobody asked for.
     *
     * @return list<string>
     */
    public static function conflictingFieldDefinitionKeys(Unit $source, Unit $target): array
    {
        $sourceKeys = UnitFieldDefinition::where('unit_id', $source->getKey())->pluck('key');
        $targetKeys = UnitFieldDefinition::where('unit_id', $target->getKey())->pluck('key')->flip();

        return $sourceKeys->filter(fn (string $key): bool => $targetKeys->has($key))->values()->all();
    }

    /**
     * Commit the merge. `$acceptedCollisions` are the dates the administrator confirmed in the
     * preview — recomputed and checked AGAINST A FRESH plan() inside the transaction, so a
     * signoff created by someone else between the preview and this submit cannot slip past a
     * stale collision list.
     *
     * @param  list<string>  $acceptedCollisions
     * @return array{handovers:int, signoffs_moved:int, signoffs_kept_on_source:int,
     *               field_definitions:int, preferred_unit_users:int, rota_assignments:int,
     *               clinics:int, reminders_moved:int, reminders_dropped:int}
     */
    public static function commit(Unit $source, Unit $target, array $acceptedCollisions, int $actorId, ?string $ip): array
    {
        if ($source->getKey() === $target->getKey()) {
            throw new RuntimeException('A unit cannot be merged into itself.');
        }

        if (! $target->active) {
            throw new RuntimeException('The target unit must be active.');
        }

        return DB::transaction(function () use ($source, $target, $acceptedCollisions, $actorId, $ip): array {
            $freshPlan = self::plan($source, $target);

            $collisions = $freshPlan['collisions'];
            sort($collisions);
            $accepted = $acceptedCollisions;
            sort($accepted);

            if ($collisions !== $accepted) {
                throw new RuntimeException(
                    'The colliding dates changed since the preview was shown. Reload and try again.'
                );
            }

            $conflictingKeys = self::conflictingFieldDefinitionKeys($source, $target);

            if ($conflictingKeys !== []) {
                throw new RuntimeException(
                    'Both units define a custom field with the same key ('
                    .implode(', ', $conflictingKeys).'). Rename or retire one before merging.'
                );
            }

            // The whole set is validated before ANY of it is mutated. `ClinicWriter` would refuse
            // this too, but it would refuse it after the handovers had already moved — a rollback
            // rather than a refusal, and a 500 rather than a sentence naming the tick-box.
            $homelessClinics = self::clinicsTheTargetCannotOwn($source, $target);

            if ($homelessClinics > 0) {
                throw new RuntimeException(
                    $homelessClinics.' clinic(s) on the source unit have nowhere to go: the target '
                    .'unit does not own clinics. Tick "owns clinics" on it in Admin → Structure → '
                    .'Units first.'
                );
            }

            $collisionSet = array_flip($collisions);

            // 1. Handovers: every row (including soft-deleted) re-points cleanly — no unique
            // constraint on (unit_id, handover_date) here (many patient rows share a day).
            $handoversMoved = Handover::withTrashed()->where('unit_id', $source->getKey())
                ->update(['unit_id' => $target->getKey()]);

            // 2. Signoffs: every NON-colliding row re-points. A colliding row's header STAYS on
            // the (now retired) source — never deleted, never silently overwritten. The
            // handover rows for that date already moved in step 1; the attestation that covered
            // them remains reachable through the source unit's id, exactly as signed.
            $signoffs = HandoverSignoff::withTrashed()->where('unit_id', $source->getKey())->get();
            $signoffsMoved = 0;
            $signoffsKept = 0;

            foreach ($signoffs as $signoff) {
                $dateKey = $signoff->handover_date->format('Y-m-d');

                if (isset($collisionSet[$dateKey])) {
                    $signoffsKept++;

                    continue;
                }

                $signoff->unit_id = $target->getKey();
                $signoff->save();
                $signoffsMoved++;
            }

            // 3. Field definitions: no key collision survived the check above, so this always
            // re-points cleanly.
            $fieldDefinitionsMoved = UnitFieldDefinition::where('unit_id', $source->getKey())
                ->update(['unit_id' => $target->getKey()]);

            // 4. users.preferred_unit_id is a real FOREIGN KEY to units.id (2026_07_24_140001),
            // not the code string UN-01's plan text described — an explicit UPDATE regardless,
            // because retiring rather than deleting the source means the FK would otherwise
            // keep pointing at a unit nothing else leads a user to any more, stranding them at
            // a 404 on /endorsement/today.
            $usersMoved = User::where('preferred_unit_id', $source->getKey())
                ->update(['preferred_unit_id' => $target->getKey()]);

            // 5. The master rota, through its ONE writer, row by row so the containment and overlap
            // guards run on every span. No collision is reachable here — the overlap rule is per
            // (person, period) and never reads `unit_id` — and a split across both units simply
            // lands as two adjacent spans on the survivor.
            $rotaMoved = RotaAssignment::repointUnit($source, $target);

            // 6. Clinics, through THEIR one writer. Nothing collides (no unique key on (unit_id,
            // weekday, session), deliberately), and the target's ownership was settled above.
            // Ordering against step 5 is irrelevant — `ClinicRoster` resolves attendees at READ
            // time — but they must share this transaction, or a migrated clinic in the default
            // `rotators` mode resolves to nobody with no error anywhere.
            $clinicsMoved = ClinicWriter::repointUnit($source, $target);

            // 7. Reminder opt-ins. UNIQUE(user_id, unit_id) makes an account opted in to BOTH units
            // the one genuine collision left, and its resolution is to drop the source's row: the
            // target's row says the same thing about the unit that survives. Dropped rather than
            // stranded, because `endorsement:remind` iterates ACTIVE units only — a row left on the
            // retired source is a reminder that never fires again and no screen can repair.
            $reminders = self::reminderOptIns($source, $target);

            if ($reminders['dropping'] !== []) {
                DB::table('reminder_preferences')
                    ->where('unit_id', $source->getKey())
                    ->whereIn('user_id', $reminders['dropping'])
                    ->delete();
            }

            $remindersMoved = DB::table('reminder_preferences')
                ->where('unit_id', $source->getKey())
                ->update(['unit_id' => $target->getKey()]);

            // 8. Retire the source. NEVER delete it — every audit row, every handover revision
            // and the kept-behind signoff headers all refer to it by id.
            $source->update(['active' => false]);

            AuditLog::record(
                'unit_merge',
                'source='.$source->getKey().';target='.$target->getKey()
                    .';handovers='.$handoversMoved
                    .';signoffs='.$signoffsMoved
                    .';signoffs_kept='.$signoffsKept
                    .';defs='.$fieldDefinitionsMoved
                    .';users='.$usersMoved
                    .';rota='.$rotaMoved
                    .';clinics='.$clinicsMoved
                    .';reminders='.$remindersMoved
                    .';reminders_dropped='.count($reminders['dropping']),
                $actorId,
                $ip,
            );

            foreach ($collisions as $date) {
                AuditLog::record(
                    'unit_merge_collision_kept',
                    'source='.$source->getKey().';target='.$target->getKey().';date='.$date,
                    $actorId,
                    $ip,
                );
            }

            return [
                'handovers' => $handoversMoved,
                'signoffs_moved' => $signoffsMoved,
                'signoffs_kept_on_source' => $signoffsKept,
                'field_definitions' => $fieldDefinitionsMoved,
                'preferred_unit_users' => $usersMoved,
                'rota_assignments' => $rotaMoved,
                'clinics' => $clinicsMoved,
                'reminders_moved' => $remindersMoved,
                'reminders_dropped' => count($reminders['dropping']),
            ];
        });
    }
}
