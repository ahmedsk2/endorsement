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

        // The four first-class clinical units. Codes are the routing identity
        // (/endorsement/{code}); names appear on screens and the printed sheet.
        $units = [
            'PICU' => 'Pediatric Intensive Care Unit',
            'NICU' => 'Neonatal Intensive Care Unit',
            'SCBU' => 'Special Care Baby Unit',
            'WARD' => 'Pediatric Ward',
        ];
        foreach ($units as $code => $name) {
            Unit::updateOrCreate(['code' => $code], ['name' => $name]);
        }

        // Tenant anchor.
        Institution::updateOrCreate(
            ['code' => 'QCH'],
            ['name' => 'Qatif Central Hospital', 'active' => true]
        );
    }
}
