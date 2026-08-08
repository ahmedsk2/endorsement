<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\Position;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class ReferenceSeeder extends Seeder
{
    /**
     * Seed the reference/lookup rows every environment needs.
     *
     * Idempotent: safe to run repeatedly (uses updateOrCreate on natural keys).
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
            'PICU' => ['Pediatric Intensive Care Unit', 1, [], 'Bed', true, 'Consultant covering', 'channel-bar-picu', 'Plan Of Care', 'New events'],
            'NICU' => ['Neonatal Intensive Care Unit', 2, ['dob'], 'Bed', true, 'Consultant covering', 'channel-bar-nicu', 'Plan Of Care', 'To be followed'],
            'SCBU' => ['Special Care Baby Unit', 3, ['dob'], 'Bed', true, 'Consultant covering', 'channel-bar-scbu', 'Plan Of Care', 'To be followed'],
            'WARD' => ['Pediatric Ward', 4, ['age', 'ward_unit'], 'Room', false, 'Consultant Oncall', 'channel-bar-ward', 'Management', 'To be followed'],
        ];

        foreach ($units as $code => $u) {
            $unit = Unit::firstOrNew(['code' => $code]);
            $unit->name = $u[0];

            if (! $unit->exists) {
                $unit->fill([
                    'display_order' => $u[1],
                    'active' => true,
                    'extra_row_fields' => $u[2],
                    'bed_label' => $u[3],
                    'consultant_pair' => $u[4],
                    'consultant_by_label' => $u[5],
                    'bar_class' => $u[6],
                    'print_plan_label' => $u[7],
                    'print_narrative_label' => $u[8],
                ]);
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
