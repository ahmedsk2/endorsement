<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\Unit;
use App\Models\UnitFieldDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Munawib UN-01's merge. ONE definition of what a merge does, shared by the preview screen and
 * the committing endpoint — a preview computed one way and a commit performed another is the
 * drift `SignoffPickers` and `AuditChain::canonical()` both carry docblocks about.
 *
 * THE COLLISION: `handover_signoffs` carries UNIQUE(unit_id, handover_date)
 * (2026_07_24_130002:77). Two units each signed off on the same date cannot both re-point onto
 * the target. That is discovered in `plan()` and CONFIRMED by a human before `commit()` runs —
 * never encountered as a 23000 in the middle of a transaction.
 *
 * A SECOND, narrower collision exists on `unit_field_definitions`' own UNIQUE(unit_id, key)
 * (2026_08_09_120001:61): two units each defining a custom field under the same key cannot both
 * re-point either. Unlike the signoff case there is no admin UI yet for custom fields (no unit
 * has ever defined one), so there is no `keep_target`-style resolution for it — it is refused
 * outright by `conflictingFieldDefinitionKeys()`, checked BEFORE any write, the same
 * pre-check-and-refuse discipline finding 14 established for `Period`'s overlap guard.
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
     * What this merge would do. Read-only; touches no data.
     *
     * `signoffs` counts rows that would actually RE-POINT (i.e. every source signoff row that
     * is NOT one of the listed `collisions`) — the same "rows this merge changes" meaning
     * `handovers` and `field_definitions` carry, since neither of those has a collision
     * exception. A colliding date's row still belongs to the source going in, so it is not
     * counted as moving.
     *
     * @return array{handovers:int, signoffs:int, field_definitions:int, preferred_unit_users:int, collisions:list<string>}
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

        return [
            'handovers' => $handovers,
            'signoffs' => $sourceDates->count() - count($collisions),
            'field_definitions' => $fieldDefinitions,
            'preferred_unit_users' => $preferredUnitUsers,
            'collisions' => $collisions,
        ];
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
     * @return array{handovers:int, signoffs_moved:int, signoffs_kept_on_source:int, field_definitions:int, preferred_unit_users:int}
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

            // 5. Retire the source. NEVER delete it — every audit row, every handover revision
            // and the kept-behind signoff headers all refer to it by id.
            $source->update(['active' => false]);

            AuditLog::record(
                'unit_merge',
                'source='.$source->getKey().';target='.$target->getKey()
                    .';handovers='.$handoversMoved
                    .';signoffs='.$signoffsMoved
                    .';signoffs_kept='.$signoffsKept
                    .';defs='.$fieldDefinitionsMoved
                    .';users='.$usersMoved,
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
            ];
        });
    }
}
