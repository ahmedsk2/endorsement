<?php

namespace App\Support\Engine;

use App\Models\Period;
use App\Support\Calendar;
use InvalidArgumentException;

/**
 * CG-10's three arguments, assembled together: `{schedule, context, conditions}` (P2 Task 24).
 *
 * `ContextBuilder` answers *"what is true of this department over this range"*. This class answers
 * the question one layer up — *"which range, and what is being evaluated inside it"* — and it
 * exists because those two answers must not be given by two callers who could disagree.
 *
 * ## THE DEFECT THIS FILE IS THE FIX FOR (P2 Task 21, finding 6)
 *
 * `ContextBuilder::forHorizon()` builds the day vector AND the availability denominator over the
 * single range it is handed. The CG-10 horizon carries FOUR dates, not two: `[from, to]` is the
 * period being drafted, `[evaluableFrom, evaluableTo]` is how far the read-only carry-in tail
 * reaches, and the second is a superset of the first.
 *
 * So a caller that builds the context over the PERIOD and then widens `evaluableFrom` to reach the
 * tail has told the engine two incompatible things. Every rolling window overlapping the tail is
 * then `fullyEvaluable` — the horizon says so — while the availability list covering it stops at
 * the period start. **Absence of data and unavailability are indistinguishable in a list of
 * dates**, so `call_frequency_max` reads a four-week window as *"this person was available on one
 * of these days"*, permits `floor(1 / 3) = 0` calls, and reports a breach on a month that breaches
 * nothing. It is a false positive of the worst kind: loudest exactly where the schedule is fine.
 *
 * ## HOW IT IS CLOSED — STRUCTURALLY, NOT BY DISCIPLINE
 *
 * The two evaluable bounds are **read off the day vector that was actually built**. They are not a
 * second copy of the range, computed alongside and passed in parallel; there is no expression in
 * this file that could hand the horizon a date the context does not describe. A future caller that
 * needs a different context range gets a matching horizon for free, and one that widens the horizon
 * has to widen the read that answers it, because they are the same statement.
 *
 * The widening itself is derived rather than configured: the range is the smallest one containing
 * the period and every date a supplied duty OCCUPIES — end date `date + spanDays - 1`, so a weekly
 * slot anchored on the last date of a period drags the right edge across the six dates it really
 * covers rather than stopping at its anchor.
 *
 * `historyAvailableFrom` travels through UNTOUCHED and is deliberately not folded into the range.
 * It is the caller's claim about how far back it looked, the engine already refuses to measure a
 * window the context cannot cover, and a claim reaching further back than the supplied duties buys
 * nothing there — clamping it here would answer a question nobody asked, in a file whose entire job
 * is to assemble.
 *
 * ## THIS FILE DECIDES NOTHING A CONDITION WOULD DECIDE
 *
 * It narrows no population, filters no duty and defaults no parameter. The one refusal it performs
 * — a duty naming a slot the document does not supply — is document integrity rather than a rule:
 * the engine throws on exactly that input, and the entrypoint reports a throw as a BUG with a stack
 * trace, so refusing here turns an exit code that means *"the engine is broken"* into a sentence
 * naming the slot. `EngineHoldsNoRuleTest` and `EngineIsAReaderTest` glob this namespace and both
 * cover this file unasked, which is the point of putting it here.
 */
final class EvaluationRequest
{
    /**
     * Build the whole request for one period.
     *
     * @param  array<string, mixed>  $document  the caller's own halves — the shipped schema records
     *                                          none of them (P2 Task 1 findings 2 and 17, owner decision R)
     * @return array{schedule: array<string, mixed>, context: array<string, mixed>, conditions: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException when the document is not the shape this assembler reads
     */
    public static function forPeriod(Period $period, array $document): array
    {
        $slots = self::listAt($document, 'slots');
        $duties = self::listAt($document, 'duties');
        $priorDuties = self::listAt($document, 'priorDuties');
        $followingDuties = self::listAt($document, 'followingDuties');
        $conditions = self::listAt($document, 'conditions');
        $unwantedDays = self::mapOfDatesAt($document, 'unwantedDays');
        $historyAvailableFrom = self::optionalDateAt($document, 'historyAvailableFrom');

        $span = self::spanOfEachSlot($slots);

        // The range the context must answer over. Consumed by the loader below and by NOTHING
        // else — the horizon reads its own bounds back out of the answer.
        $rangeFrom = Calendar::ymd($period->starts_on);
        $rangeTo = Calendar::ymd($period->ends_on);

        foreach ([$duties, $priorDuties, $followingDuties] as $stream) {
            foreach ($stream as $index => $duty) {
                [$starts, $ends] = self::datesOccupiedBy($duty, $span, $index);

                if ($starts < $rangeFrom) {
                    $rangeFrom = $starts;
                }

                if ($ends > $rangeTo) {
                    $rangeTo = $ends;
                }
            }
        }

        $context = ContextBuilder::forHorizon(
            $rangeFrom,
            $rangeTo,
            $slots,
            $priorDuties,
            $followingDuties,
            $historyAvailableFrom,
            $unwantedDays,
        );

        // THE FIX, in three lines. The evaluable bounds are the day vector's own first and last
        // dates, so the horizon cannot claim a range the context does not describe.
        $days = $context['days'];
        $evaluableFrom = $days[0]['date'];
        $evaluableTo = $days[array_key_last($days)]['date'];

        return [
            'schedule' => [
                'horizon' => [
                    'from' => Calendar::ymd($period->starts_on),
                    'to' => Calendar::ymd($period->ends_on),
                    'evaluableFrom' => $evaluableFrom,
                    'evaluableTo' => $evaluableTo,
                ],
                'duties' => array_values($duties),
            ],
            'context' => $context,
            'conditions' => array_values($conditions),
        ];
    }

    /**
     * `periods.academic_year` + `periods.position`, the table's own UNIQUE key — the same spelling
     * `ContextBuilder` gives a period inside the payload, so the key an operator types on the
     * command line is the key they read back out of a finding.
     */
    public static function periodKey(Period $period): string
    {
        return $period->academic_year.'-'.sprintf('%02d', (int) $period->position);
    }

    /**
     * The first and last date one duty occupies.
     *
     * Duty end is `date + spanDays - 1`: a one-day slot ends on its anchor date, and a seven-day
     * one ends six dates later. The arithmetic goes through the one converter (AR-08), and the
     * comparison either side of it is on `Y-m-d` strings the converter formatted, which sort as
     * text exactly as they sort as time.
     *
     * @param  mixed  $duty
     * @param  array<string, int>  $span
     * @return array{0: string, 1: string}
     */
    private static function datesOccupiedBy(mixed $duty, array $span, int|string $index): array
    {
        if (! is_array($duty) || ! is_string($duty['date'] ?? null) || ! is_string($duty['slotKey'] ?? null)) {
            throw new InvalidArgumentException(
                "Duty {$index} needs a \"date\" and a \"slotKey\", both strings."
            );
        }

        $slotKey = $duty['slotKey'];

        if (! array_key_exists($slotKey, $span)) {
            throw new InvalidArgumentException(
                "A duty names the slot \"{$slotKey}\", which this document does not supply. Slots are "
                .'the caller\'s own half — SL-01 stores none of this yet — so every slotKey a duty '
                .'uses has to appear in "slots".'
            );
        }

        $starts = Calendar::ymd($duty['date']);

        return [$starts, Calendar::ymd(Calendar::addDays($starts, $span[$slotKey] - 1))];
    }

    /**
     * How many dates each supplied slot's window is anchored across.
     *
     * @param  list<mixed>  $slots
     * @return array<string, int>
     */
    private static function spanOfEachSlot(array $slots): array
    {
        $span = [];

        foreach ($slots as $index => $slot) {
            if (! is_array($slot) || ! is_string($slot['key'] ?? null)) {
                throw new InvalidArgumentException("Slot {$index} needs a string \"key\".");
            }

            $days = $slot['spanDays'] ?? null;

            if (! is_int($days) || $days < 1) {
                throw new InvalidArgumentException(
                    "Slot \"{$slot['key']}\" needs a whole \"spanDays\" of at least 1."
                );
            }

            $span[$slot['key']] = $days;
        }

        return $span;
    }

    /**
     * One key of the document, as a list. Absent reads as empty; present-and-not-a-list is refused
     * rather than coerced, because a JSON object where a list belongs is a document somebody has
     * mistyped and half-reading it is how a whole stream goes silently missing.
     *
     * @param  array<string, mixed>  $document
     * @return list<mixed>
     */
    private static function listAt(array $document, string $key): array
    {
        $value = $document[$key] ?? [];

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("\"{$key}\" must be a JSON array.");
        }

        return $value;
    }

    /**
     * `unwantedDays`, keyed by person key (owner decision R: P2 stores none of this, so it arrives
     * from the caller or not at all).
     *
     * @param  array<string, mixed>  $document
     * @return array<string, list<string>>
     */
    private static function mapOfDatesAt(array $document, string $key): array
    {
        $value = $document[$key] ?? [];

        // A JSON object reaches PHP as an associative array or, when it is EMPTY and the caller
        // took care to keep the distinction, as a `stdClass`. Both spell the same map and both are
        // read as one here; see `EngineEvaluate::withEmptyObjectsKept()` for why the distinction is
        // worth keeping at all.
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw new InvalidArgumentException("\"{$key}\" must be a JSON object keyed by person key.");
        }

        $out = [];

        foreach ($value as $personKey => $dates) {
            if (! is_array($dates) || ! array_is_list($dates)) {
                throw new InvalidArgumentException("\"{$key}.{$personKey}\" must be a JSON array of dates.");
            }

            foreach ($dates as $date) {
                if (! is_string($date)) {
                    throw new InvalidArgumentException("\"{$key}.{$personKey}\" holds a date that is not a string.");
                }
            }

            $out[(string) $personKey] = array_values($dates);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function optionalDateAt(array $document, string $key): ?string
    {
        $value = $document[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("\"{$key}\" must be a date string or null.");
        }

        return $value;
    }
}
