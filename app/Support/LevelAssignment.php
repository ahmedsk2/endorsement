<?php

namespace App\Support;

use App\Models\Level;
use App\Models\Person;
use App\Models\PersonLevel;
use Illuminate\Support\Facades\DB;

/**
 * The ONE writer of `person_levels` (P1c Decision G). Finding 4: before this existed, the table
 * had no overlap constraint, no batch identity, no reason and no author — two open-ended spans
 * for one person coexisted happily and `Person::levelAt()` silently resolved whichever sorted
 * last, no error, no warning.
 *
 * `tests/Feature/Build/PersonLevelsHaveOneWriterTest.php` asserts at source level that nothing
 * else in app/, database/ or routes/ writes this table.
 *
 * NEVER UPSERTS. A collision on unique(person_id, effective_from) is SKIPPED, not overwritten —
 * an upsert on that key would silently rewrite what level someone held on a date that may already
 * be rendered beside a signed handover. Skipping and reporting a named outcome is the only safe
 * branch, which is why every path here returns one of the four constants below rather than
 * throwing on the routine, expected collisions.
 *
 * Writes NO audit row. A bulk caller (Task 10's promotion) writes one summary row plus one row
 * per person (Decision H); writing here too would be the per-write-inside-a-transaction unwind
 * problem `ManagerScope::assertMayTarget()` already carries a docblock about.
 */
final class LevelAssignment
{
    public const ASSIGNED = 'assigned';

    public const SKIPPED_EXISTING = 'skipped_existing';

    public const SKIPPED_SAME_LEVEL = 'skipped_same_level';

    public const REFUSED_OVERLAP = 'refused_overlap';

    /**
     * @param  array{batch?: ?string, reason?: ?string, actor?: ?int}  $context
     * @return self::ASSIGNED|self::SKIPPED_EXISTING|self::SKIPPED_SAME_LEVEL|self::REFUSED_OVERLAP
     */
    public static function assign(Person $person, Level $level, string $effectiveFrom, array $context = []): string
    {
        // Y-m-d ONLY — Calendar::parse() throws otherwise. No lenient sibling: leniency here is
        // exactly what let "+5 years" create backdated clinical rows elsewhere in this codebase.
        $from = Calendar::parse($effectiveFrom)->format(Calendar::YMD);

        // 1. An EXACT-DATE collision is checked first, ahead of the same-level check below: the
        // unique(person_id, effective_from) index is the constraint that must never be upserted
        // through, whatever level either side names — a second call naming the SAME level on the
        // SAME date must be reported identically to one naming a DIFFERENT level there, because
        // in both cases the existing row must survive untouched.
        $existing = PersonLevel::query()
            ->where('person_id', $person->getKey())
            ->whereDate('effective_from', $from)
            ->exists();

        if ($existing) {
            return self::SKIPPED_EXISTING;
        }

        // 2. The level already in force on that date, from an EARLIER span — a genuine no-op
        // regardless of the newly-proposed effective_from, so no new span is written at all.
        if ($person->levelAt($from)?->is($level)) {
            return self::SKIPPED_SAME_LEVEL;
        }

        // 3. Refuse to write BEHIND a span that already starts later — writing here would
        // silently rewrite history that a later, already-recorded span has already claimed.
        $laterSpanExists = PersonLevel::query()
            ->where('person_id', $person->getKey())
            ->whereDate('effective_from', '>', $from)
            ->exists();

        if ($laterSpanExists) {
            return self::REFUSED_OVERLAP;
        }

        DB::transaction(function () use ($person, $level, $from, $context): void {
            // Close the open prior span (if any) to the day BEFORE the new span starts.
            // Person::levelAt() is inclusive at both ends, so this is what leaves no gap and no
            // overlap: the old level is still in force on the day before, the new one from day one.
            PersonLevel::query()
                ->where('person_id', $person->getKey())
                ->whereNull('effective_to')
                ->update(['effective_to' => Calendar::addDays($from, -1)->format(Calendar::YMD)]);

            PersonLevel::create([
                'person_id' => $person->getKey(),
                'level_id' => $level->getKey(),
                'effective_from' => $from,
                'effective_to' => null,
                'promotion_batch_id' => $context['batch'] ?? null,
                'reason' => $context['reason'] ?? null,
                'created_by' => $context['actor'] ?? null,
            ]);
        });

        return self::ASSIGNED;
    }
}
