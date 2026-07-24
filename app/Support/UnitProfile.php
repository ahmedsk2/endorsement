<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The SINGLE source of per-unit variation (spec §3). Every surface — sheet columns,
 * sign-off fields, print schema, nav/chooser hues — reads from here, so the four units
 * can never drift apart the way the four copy-pasted legacy code families did.
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

    /** @var array<string, UnitProfile>|null */
    private static ?array $profiles = null;

    /** @return array<string, UnitProfile> */
    private static function profiles(): array
    {
        return self::$profiles ??= [
            'PICU' => new self(
                code: 'PICU',
                extraRowFields: [],
                bedLabel: 'Bed',
                consultantPair: true,
                consultantByLabel: 'Consultant covering',
                barClass: 'channel-bar-picu',
                printPlanLabel: 'Plan Of Care',
                printNarrativeLabel: 'New events',
            ),
            'NICU' => new self(
                code: 'NICU',
                extraRowFields: ['dob'],
                bedLabel: 'Bed',
                consultantPair: true,
                consultantByLabel: 'Consultant covering',
                barClass: 'channel-bar-nicu',
                printPlanLabel: 'Plan Of Care',
                printNarrativeLabel: 'To be followed',
            ),
            'SCBU' => new self(
                code: 'SCBU',
                extraRowFields: ['dob'],
                bedLabel: 'Bed',
                consultantPair: true,
                consultantByLabel: 'Consultant covering',
                barClass: 'channel-bar-scbu',
                printPlanLabel: 'Plan Of Care',
                printNarrativeLabel: 'To be followed',
            ),
            'WARD' => new self(
                code: 'WARD',
                extraRowFields: ['age', 'ward_unit'],
                bedLabel: 'Room',
                consultantPair: false,
                consultantByLabel: 'Consultant Oncall',
                barClass: 'channel-bar-ward',
                printPlanLabel: 'Management',
                printNarrativeLabel: 'To be followed',
            ),
        ];
    }

    /** The four first-class unit codes, in display order. @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::profiles());
    }

    public static function for(string $code): self
    {
        $profile = self::profiles()[strtoupper($code)] ?? null;

        if ($profile === null) {
            throw new InvalidArgumentException("Unknown unit code [{$code}].");
        }

        return $profile;
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
