/**
 * One person's duties as a single chronological line — across the horizon AND its carry-in tail.
 *
 * ## Why the tail is in here rather than left to each type
 *
 * Every window-measured and pairwise type measures a relationship that crosses the boundary of
 * what is being evaluated: the 10 h gap between the last night of month M and the first duty of
 * M+1, a post-duty exclusion carrying into the 1st, a run spanning the 31st into the 1st. A draft
 * is built one period at a time and the preceding period is a different, already-published
 * schedule. A type that only ever sees `inHorizon` is systematically wrong at exactly the dates a
 * scheduler hits first.
 *
 * So the neighbours arrive as context: `prior` and `following` are READ-ONLY, they are never
 * re-evaluated, and {@link PositionedDuty.origin} is how a type knows which is which. That is what
 * keeps CG-03's *"never retroactive on published schedules"* intact while still letting a gap be
 * measured across the seam — reading last month's duties is not re-evaluating last month.
 *
 * ## Why the slot is resolved here
 *
 * Ordering two duties on one date needs their windows, so this module resolves the slot anyway;
 * handing back the resolved slot and interval stops every consumer re-deriving both. A duty naming
 * a slot nobody supplied **throws** rather than being skipped — a silently dropped duty is a
 * control that appears to do nothing, which is this codebase's most expensive recurring failure
 * shape, and here it would silently weaken every cap that duty contributes to.
 */

import { dutyInterval, type AbsInterval, type Duty, type Slot } from './interval';

/** Where a duty came from, and therefore whether a violation may be located on it. */
export type DutyOrigin = 'prior' | 'horizon' | 'following';

/**
 * The three duty streams: the schedule's own `duties`, and the carry-in tail either side of it.
 *
 * The field names are the evaluation context's own (`priorDuties`, `followingDuties`, Decision A)
 * so that Task 7's caller passes them through rather than renaming them at the boundary — a
 * rename at a boundary is where an argument eventually gets passed in the wrong order.
 * `followingDuties` is usually empty and is never ASSUMED empty: re-drafting an earlier period of
 * an already-published year has duties on both sides.
 */
export interface DutyStreams {
    priorDuties: readonly Duty[];
    duties: readonly Duty[];
    followingDuties: readonly Duty[];
}

/** A duty with its slot resolved, its interval computed, and its provenance kept. */
export interface PositionedDuty {
    duty: Duty;
    slot: Slot;
    interval: AbsInterval;
    origin: DutyOrigin;
}

/** A resolved slot lookup. `get` throws on an unknown key; see the module docblock. */
export interface SlotIndex {
    get(key: string): Slot;
    has(key: string): boolean;
}

/**
 * Index a slot list by key.
 *
 * A duplicate key throws: two slots answering to one name makes every lookup arbitrary, and the
 * arbitrary answer would differ between the browser runtime and the Node one for no visible
 * reason (D4 gives the engine two runtimes, and "it worked on my machine" is not available to a
 * pure function).
 */
export function slotIndex(slots: readonly Slot[]): SlotIndex {
    const bySlotKey = new Map<string, Slot>();

    for (const slot of slots) {
        if (bySlotKey.has(slot.key)) {
            throw new RangeError(`Two slots share the key "${slot.key}"; a slot key identifies one slot.`);
        }

        bySlotKey.set(slot.key, slot);
    }

    return {
        has: (key: string): boolean => bySlotKey.has(key),
        get: (key: string): Slot => {
            const slot = bySlotKey.get(key);

            if (slot === undefined) {
                throw new RangeError(
                    `No slot named "${key}". A duty referencing an unsupplied slot is dropped context, ` +
                        'not an empty schedule — every cap it contributes to would silently loosen.',
                );
            }

            return slot;
        },
    };
}

/**
 * One person's duties, ordered on the absolute-minute line, across all three streams.
 *
 * Ordered by interval start, then interval end, then slot key — the last only as a tie-break, so
 * the order is total and a fixture comparison is stable. `Array.prototype.sort` is stable, so two
 * duties that tie on all three keep prior → horizon → following order, which is the order they are
 * concatenated in.
 */
export function orderedDutiesFor(personKey: string, streams: DutyStreams, slots: SlotIndex): PositionedDuty[] {
    const positioned: PositionedDuty[] = [];

    const collect = (duties: readonly Duty[], origin: DutyOrigin): void => {
        for (const duty of duties) {
            if (duty.personKey !== personKey) {
                continue;
            }

            const slot = slots.get(duty.slotKey);

            positioned.push({ duty, slot, interval: dutyInterval(duty, slot), origin });
        }
    };

    collect(streams.priorDuties, 'prior');
    collect(streams.duties, 'horizon');
    collect(streams.followingDuties, 'following');

    return positioned.sort(
        (a, b) =>
            a.interval.start - b.interval.start ||
            a.interval.end - b.interval.end ||
            (a.duty.slotKey < b.duty.slotKey ? -1 : a.duty.slotKey > b.duty.slotKey ? 1 : 0),
    );
}
