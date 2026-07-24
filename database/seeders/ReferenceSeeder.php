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
        $positions = [
            0 => 'Administrator',
            1 => 'Nurse',
            2 => 'Charge Nurse',
            3 => 'Consultant',
            4 => 'Resident',
        ];
        foreach ($positions as $id => $name) {
            Position::updateOrCreate(['id' => $id], ['name' => $name]);
        }

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
