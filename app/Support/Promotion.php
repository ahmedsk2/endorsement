<?php

namespace App\Support;

use App\Models\Level;
use App\Models\Person;
use App\Models\PersonLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Munawib LV-03's annual promotion — P1b Owner Decision A, restated: there is no terminal level
 * and none is inferred (a `terminal` column and a "what comes after this level" inference method
 * on `Level` were deliberately not built — `LevelLadderTest` pins their absence). The OPERATOR
 * chooses both the source level (the cohort)
 * and the target level explicitly. Nothing here computes a target — no `display_order + 10`, no
 * "the next level by order" fallback. `EXT` is on neither end
 * (`PromotionController::offerableLevels()` is `Level::query()->internal()->active()->ordered()`,
 * the one predicate offered and validated from).
 *
 * ONE ACTION, PREVIEWED, SINGLE-TRANSACTION, AUDITED (LV-03's own shape, kept). Two public
 * methods and no state.
 */
final class Promotion
{
    /**
     * The PREVIEW. Computes, for every active person currently at `$from`, what `assign()` (or,
     * for a null `$to`, `LevelAssignment::close()`) WOULD return — asking the SAME questions
     * those writers ask, via `LevelAssignment::predictOutcome()`, in the same order. It does not
     * write, and it does not guess: a preview whose outcomes are derived differently from the
     * commit's is a preview that lies on exactly the rows that matter.
     *
     * `is_backwards` is true when `$to`'s `display_order` is LOWER than `$from`'s — R4 -> R1, say.
     * The system does not know that is wrong and must not pretend to; it only states the fact
     * plainly so a human can decide.
     *
     * @return array{cohort: list<array{person_id:int, name:string, outcome:string}>, batch:string, is_backwards:bool}
     */
    public static function preview(Level $from, ?Level $to, string $effectiveFrom): array
    {
        $on = Calendar::parse($effectiveFrom)->format(Calendar::YMD);

        $rows = self::cohort($from, $on)->get()
            ->map(fn (Person $person): array => [
                'person_id' => (int) $person->getKey(),
                'name' => (string) $person->full_name,
                'outcome' => $to === null
                    ? self::predictClose($person)
                    : LevelAssignment::predictOutcome($person, $to, $on),
            ])
            ->values()
            ->all();

        return [
            'cohort' => $rows,
            // Not carried forward to commit() — commit() mints its own, because a preview may be
            // run more than once (changing the date, excluding a row) before anything is
            // written, and the WRITTEN batch must identify the act that actually happened.
            'batch' => (string) Str::uuid(),
            'is_backwards' => $to !== null && (int) $to->display_order < (int) $from->display_order,
        ];
    }

    /**
     * The COMMIT. Re-derives the cohort INSIDE the transaction and intersects it with the
     * submitted id list, so a person added to the source level between preview and commit is not
     * silently swept in and one removed does not fail the whole run — the same discipline
     * `UnitMerge::commit()` established for signoff collisions (P1b Task 5).
     *
     * Writes nothing but `person_levels` (through `LevelAssignment`, the one writer, Decision G),
     * `people.active` and the linked account's `users.active` (both through
     * `App\Support\PersonStatus::apply()`, review finding 4 — the retire-cohort path only).
     * NEVER CREATES an account — see `RosterNeverMintsCredentialsTest` (Decision I is about
     * minting a credential, not about every write to `users`; deactivating one that already
     * exists is not that).
     *
     * Audits NOTHING itself. The caller writes one summary row plus one row per person AFTER this
     * returns and the transaction has committed (Decision H): `AuditLog::record()` opens its own
     * transaction and locks the chain tail, so N of them nested inside this one would serialise
     * the whole chain for the batch's duration and unwind the attempt's own trail on rollback —
     * the same ordering `AccessControlController::applyRoleSet()` already establishes.
     *
     * @param  list<int>  $ids  the cohort ids the operator confirmed on the preview screen
     * @param  array{actor?: ?int, reason?: ?string}  $context
     * @return array{batch:string, outcomes: array<int, string>}
     */
    public static function commit(Level $from, ?Level $to, string $effectiveFrom, array $ids, array $context = []): array
    {
        return DB::transaction(function () use ($from, $to, $effectiveFrom, $ids, $context): array {
            $on = Calendar::parse($effectiveFrom)->format(Calendar::YMD);

            $fresh = self::cohort($from, $on)->get()->keyBy(fn (Person $p): int => (int) $p->getKey());
            $selectedIds = array_values(array_intersect(array_map('intval', $ids), $fresh->keys()->all()));

            $batchId = (string) Str::uuid();
            $outcomes = [];

            foreach ($selectedIds as $id) {
                /** @var Person $person */
                $person = $fresh->get($id);

                if ($to === null) {
                    // Decision D's graduation path: deactivate AND close the open span. Two
                    // separate writes because they answer two separate questions (naming vs
                    // level history) — the same separation P0c drew between `people.active` and
                    // `users.active`. `PersonStatus::apply()` (review finding 4) is what actually
                    // draws that line: before it existed this wrote the person's flag directly,
                    // in one Eloquent call, and never touched the linked account — so a person
                    // retired through this path stayed named as gone but able to log in.
                    PersonStatus::apply($person, false);
                    $outcomes[$id] = LevelAssignment::close($person, $on);
                } else {
                    $outcomes[$id] = LevelAssignment::assign($person, $to, $on, [
                        'batch' => $batchId,
                        'reason' => $context['reason'] ?? null,
                        'actor' => $context['actor'] ?? null,
                    ]);
                }
            }

            return ['batch' => $batchId, 'outcomes' => $outcomes];
        });
    }

    /**
     * The cohort predicate, written ONCE (finding 7: table-qualified — `people` and `levels`
     * both carry `active`, and this joins toward `levels` through `person_levels`) and shared by
     * `preview()` and `commit()`'s fresh re-derivation, so the two can never drift.
     *
     * @return Builder<Person>
     */
    private static function cohort(Level $from, string $on): Builder
    {
        return Person::query()
            ->where('people.active', true)
            ->whereHas('levels', fn ($q) => $q
                ->where('person_levels.level_id', $from->getKey())
                ->whereDate('person_levels.effective_from', '<=', $on)
                ->where(fn ($i) => $i->whereNull('person_levels.effective_to')
                    ->orWhereDate('person_levels.effective_to', '>=', $on)))
            ->orderBy('people.full_name');
    }

    /** What {@see LevelAssignment::close()} would report, without writing. */
    private static function predictClose(Person $person): string
    {
        $hasOpenSpan = PersonLevel::query()
            ->where('person_id', $person->getKey())
            ->whereNull('effective_to')
            ->exists();

        return $hasOpenSpan ? LevelAssignment::CLOSED : LevelAssignment::NOTHING_TO_CLOSE;
    }
}
