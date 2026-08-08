<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\Position;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class ReferenceSeeder extends Seeder
{
    /**
     * Seed the reference/lookup rows every environment needs.
     *
     * Idempotent: safe to run repeatedly. Positions and the institution are upserted via
     * updateOrCreate on their natural keys. Units use firstOrNew instead — a re-seed must
     * refresh `name` without silently reverting an administrator's configuration, so every
     * profile column below (display_order, active, extra_row_fields, ...) is written on
     * CREATE only.
     * Capabilities are intentionally NOT seeded here (AccessControlSeeder owns those).
     */
    public function run(): void
    {
        // Role catalog (0=Administrator has no lookup row in legacy; here it is explicit).
        // Position 1 (Nurse) is RETIRED (owner ruling, 2026-07-25): an endorsement-only
        // system has no nurse surface — the legacy gate excluded them everywhere.
        // Position 5 (Chief Resident) is a Resident promoted by an Administrator; it is
        // never offered at registration.
        $positions = [
            0 => 'Administrator',
            2 => 'Charge Nurse',
            3 => 'Consultant',
            4 => 'Resident',
            5 => 'Chief Resident',
        ];
        foreach ($positions as $id => $name) {
            Position::updateOrCreate(['id' => $id], ['name' => $name]);
        }

        // Remove the retired catalog row where it exists. Catalog only — user rows are
        // never deleted by a seeder (legacy nurse accounts are simply no longer imported).
        Position::whereKey(1)->delete();

        // The first-class clinical units. Codes are the routing identity
        // (/endorsement/{code}); names appear on screens and the printed sheet.
        //
        // Profile columns are seeded ONCE for a fresh install and then belong to the
        // department — a re-seed refreshes `name` only, so an admin's configuration is never
        // silently reverted. Existing databases were backfilled by the 2026_08_08 migration.
        $units = [
            'PICU' => [
                'name' => 'Pediatric Intensive Care Unit',
                'display_order' => 1,
                'active' => true,
                'extra_row_fields' => [],
                'bed_label' => 'Bed',
                'consultant_pair' => true,
                'consultant_by_label' => 'Consultant covering',
                'bar_class' => 'channel-bar-picu',
                'print_plan_label' => 'Plan Of Care',
                'print_narrative_label' => 'New events',
            ],
            'NICU' => [
                'name' => 'Neonatal Intensive Care Unit',
                'display_order' => 2,
                'active' => true,
                'extra_row_fields' => ['dob'],
                'bed_label' => 'Bed',
                'consultant_pair' => true,
                'consultant_by_label' => 'Consultant covering',
                'bar_class' => 'channel-bar-nicu',
                'print_plan_label' => 'Plan Of Care',
                'print_narrative_label' => 'To be followed',
            ],
            'SCBU' => [
                'name' => 'Special Care Baby Unit',
                'display_order' => 3,
                'active' => true,
                'extra_row_fields' => ['dob'],
                'bed_label' => 'Bed',
                'consultant_pair' => true,
                'consultant_by_label' => 'Consultant covering',
                'bar_class' => 'channel-bar-scbu',
                'print_plan_label' => 'Plan Of Care',
                'print_narrative_label' => 'To be followed',
            ],
            'WARD' => [
                'name' => 'Pediatric Ward',
                'display_order' => 4,
                'active' => true,
                'extra_row_fields' => ['age', 'ward_unit'],
                'bed_label' => 'Room',
                'consultant_pair' => false,
                'consultant_by_label' => 'Consultant Oncall',
                'bar_class' => 'channel-bar-ward',
                'print_plan_label' => 'Management',
                'print_narrative_label' => 'To be followed',
            ],
        ];

        foreach ($units as $code => $u) {
            $unit = Unit::firstOrNew(['code' => $code]);
            $unit->name = $u['name'];

            if (! $unit->exists) {
                $unit->fill(Arr::except($u, ['name']));
            }

            $unit->save();
        }

        // Tenant anchor.
        Institution::updateOrCreate(
            ['code' => 'QCH'],
            ['name' => 'Qatif Central Hospital', 'active' => true]
        );
    }
}
