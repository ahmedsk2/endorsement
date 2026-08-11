<?php

namespace App\Support\Demo;

use RuntimeException;

/**
 * Removal was refused WHOLE because real records now reference the demo department (Munawib ST-05,
 * P1e Decision F). Nothing was deleted.
 *
 * ITS OWN TYPE, and for `StaleRotaStateException`'s reason: a caller has to be able to tell this
 * refusal apart from "no such batch" by TYPE rather than by matching an error message's text. The
 * two have different remedies — one is "deal with the real rows first", the other is "you asked
 * about a demo department that does not exist" — and message-matching is the drift this codebase
 * keeps removing.
 *
 * IT CARRIES THE COUNTS AS DATA, not only as prose. The screen that shows the refusal renders a
 * table of them and the audit row is built from the same list, so a second parse of the message
 * would be a second definition of what is blocking.
 *
 * TABLES AND COUNTS ONLY — never a person's name, a unit's name, a clinic's name or a patient's
 * anything (invariant 9). The message below is rendered to an operator AND folded into the audit
 * trail, so a name leaking into it leaks into both.
 */
final class DemoRemovalBlockedException extends RuntimeException
{
    /**
     * @param  list<array{table: string, count: int}>  $blocked
     */
    public function __construct(
        private readonly string $batch,
        private readonly array $blocked,
    ) {
        parent::__construct(self::describe($blocked));
    }

    public function batch(): string
    {
        return $this->batch;
    }

    /** @return list<array{table: string, count: int}> */
    public function blocked(): array
    {
        return $this->blocked;
    }

    /**
     * `handovers:1,users:2` — the audit detail's value, and the same list the message is built
     * from. One definition, two consumers.
     */
    public function pairs(): string
    {
        return self::pairsFor($this->blocked);
    }

    /** @param  list<array{table: string, count: int}>  $blocked */
    public static function pairsFor(array $blocked): string
    {
        return implode(',', array_map(
            static fn (array $row): string => $row['table'].':'.$row['count'],
            $blocked,
        ));
    }

    /**
     * THE REMEDY IS PER TABLE, and that is the P1e-2 review's finding 2 in miniature. This used to
     * end *"deal with those rows first (delete or re-point them)"* for every table alike, which is
     * wrong wherever the product offers neither: accounts are deactivated and never deleted,
     * sign-offs are medico-legal evidence and are never deleted at all. A refusal naming a control
     * that does not exist sends its reader to a database console.
     *
     * `DemoReferences::REMEDIES` is the one definition and `DemoRemoveTest` asserts it covers every
     * table that can appear here, so a future map entry cannot ship without one. The fallback below
     * is unreachable while that test passes and is deliberately vague rather than confidently wrong.
     *
     * @param  list<array{table: string, count: int}>  $blocked
     */
    private static function describe(array $blocked): string
    {
        $lines = array_map(
            static fn (array $row): string => $row['count'].' in '.$row['table'],
            $blocked,
        );

        $remedies = array_map(
            static fn (array $row): string => DemoReferences::REMEDIES[$row['table']]
                ?? $row['table'].': deal with those rows first.',
            $blocked,
        );

        return 'This demo department cannot be removed: real records now reference it — '
            .implode('; ', $lines).'. Deal with those rows first, then remove the demo. Nothing has '
            .'been deleted. '.implode(' ', $remedies);
    }
}
