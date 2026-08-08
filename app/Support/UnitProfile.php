<?php

namespace App\Support;

use App\Models\Unit;
use InvalidArgumentException;

/**
 * The SHAPE of per-unit variation (spec §3) — every surface (sheet columns, sign-off
 * fields, print schema, nav/chooser hues) reads a value object of this shape. Since P0a
 * (design §6.1) the VALUES themselves live on the `units` table row, not in this class:
 * `fromUnit()` builds the value object from a `Unit`, so adding a department is
 * configuration (a new row) rather than code (a new case here).
 *
 * Per-unit facts (legacy parity, confirmed against the deployed *-endorsement-*.php):
 *   PICU  — bed/mrn/name only; consultant covering+receiving pair; print narrative "New events".
 *   NICU  — adds a neonatal DOB (datetime) column.
 *   SCBU  — same shape as NICU.
 *   WARD  — "Room" instead of "Bed", adds free-text age + sub-unit ("Unit/Speciality");
 *           ONE consultant field labelled "Consultant Oncall" stored in consultant_by_*
 *           (spec ruling 5); print plan column says "Management".
 */
final class UnitProfile
{
    /**
     * @param list<string> $extraRowFields  extra writable identity columns beyond bed/mrn/name
     */
    private function __construct(
        public readonly string $code,
        public readonly array $extraRowFields,
        public readonly string $bedLabel,
        public readonly bool $consultantPair,
        public readonly string $consultantByLabel,
        public readonly string $barClass,
        public readonly string $printPlanLabel,
        public readonly string $printNarrativeLabel,
    ) {
    }

    /** Build the value object from a `units` row — the real, current API. */
    public static function fromUnit(Unit $unit): self
    {
        $code = strtoupper((string) $unit->code);

        return new self(
            code: $code,
            extraRowFields: array_values($unit->extra_row_fields ?? []),
            bedLabel: $unit->bed_label ?? 'Bed',
            consultantPair: $unit->consultant_pair ?? true,
            consultantByLabel: $unit->consultant_by_label ?? 'Consultant covering',
            barClass: $unit->bar_class ?? 'channel-bar-'.strtolower($code),
            printPlanLabel: $unit->print_plan_label ?? 'Plan Of Care',
            printNarrativeLabel: $unit->print_narrative_label ?? 'To be followed',
        );
    }

    /**
     * @deprecated Temporary DB-backed shim for callers not yet migrated to `Unit::codes()`.
     * `Unit::codes()` is the real API. Task 5 removes this once nothing calls it.
     *
     * The four first-class unit codes, in display order. @return list<string>
     */
    public static function codes(): array
    {
        return Unit::codes();
    }

    /**
     * @deprecated Temporary DB-backed shim for callers not yet migrated to `$unit->profile()`.
     * `$unit->profile()` is the real API. Task 5 removes this once nothing calls it.
     */
    public static function for(string $code): self
    {
        $unit = Unit::query()->active()->where('code', strtoupper($code))->first();

        if ($unit === null) {
            throw new InvalidArgumentException("Unknown unit code [{$code}].");
        }

        return self::fromUnit($unit);
    }

    /**
     * The client contract, shared via Inertia as `unit.profile`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'extra_row_fields' => $this->extraRowFields,
            'bed_label' => $this->bedLabel,
            'consultant_pair' => $this->consultantPair,
            'consultant_by_label' => $this->consultantByLabel,
            'bar_class' => $this->barClass,
            'plan_label' => $this->printPlanLabel,
            'narrative_label' => $this->printNarrativeLabel,
        ];
    }
}
