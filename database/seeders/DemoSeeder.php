<?php

namespace Database\Seeders;

use App\Models\Handover;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * LOCAL TRY-OUT ONLY — never run in production. Creates one account per role (shared,
 * obviously-fake password) and a small fictional PICU census on yesterday's date so the
 * "New day" carry-forward can be tried immediately. Not referenced by DatabaseSeeder.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // HARD STOP. These are fictional accounts with a password published in the repo
        // docs; running this against production would create working logins into a system
        // holding children's health data. There is deliberately no override flag.
        if (app()->environment('production')) {
            throw new \RuntimeException('DemoSeeder must never run in production.');
        }

        $accounts = [
            ['admin', 'Demo Administrator', 0],
            ['charge1', 'Demo Charge Nurse', 2],
            ['consultant1', 'Dr Demo Consultant', 3],
            ['consultant2', 'Dr Second Consultant', 3],
            ['resident1', 'Dr Demo Resident', 4],
            ['resident2', 'Dr Second Resident', 4],
            ['chief1', 'Dr Demo Chief Resident', 5],
        ];

        foreach ($accounts as [$name, $full, $position]) {
            User::updateOrCreate(
                ['member_name' => $name],
                [
                    'full_name' => $full,
                    'member_email' => $name.'@demo.example.org',
                    'password' => 'demo-pass-1234',
                    'position' => $position,
                    'active' => true,
                    'pass_exp_date' => now()->format('Y-m-d'),
                ],
            );
        }

        // Yesterday's PICU sheet — entirely fictional patients — so "New day" has a
        // census to carry forward on first click.
        $picu = Unit::where('code', 'PICU')->first();

        if ($picu !== null && ! Handover::where('unit_id', $picu->id)->exists()) {
            $yesterday = now()->subDay()->format('Y-m-d');

            $rows = [
                ['2', 'DEMO-001', 'Test Patient A', '<p><b>Septic shock</b></p>', '<p>On noradrenaline, weaning</p>', '<p>Wean vasopressors, repeat lactate</p>', '<p>Blood culture pending</p>'],
                ['5', 'DEMO-002', 'Test Patient B', '<p>Bronchiolitis</p>', '<p><i>Stable on HFNC</i></p>', '<ul><li>Wean flow</li><li>Trial off overnight</li></ul>', ''],
                ['7', 'DEMO-003', 'Test Patient C', '<p>DKA, resolved</p>', '<p>On maintenance fluids</p>', '<p>Start subcut insulin, endocrine review</p>', '<p><u>Family meeting tomorrow</u></p>'],
            ];

            foreach ($rows as [$bed, $mrn, $name, $disease, $details, $plan, $nevent]) {
                Handover::create([
                    'unit_id' => $picu->id,
                    'handover_date' => $yesterday,
                    'bed' => $bed,
                    'mrn' => $mrn,
                    'patient_name' => $name,
                    'disease' => $disease,
                    'details' => $details,
                    'plan' => $plan,
                    'nevent' => $nevent,
                ]);
            }
        }
    }
}
