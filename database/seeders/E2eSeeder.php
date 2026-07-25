<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The browser e2e harness's identity. LOCAL ONLY — the Playwright fixtures refuse to run
 * against any non-loopback host, and this seeder is only ever invoked by the harness's
 * global setup against the throwaway e2e sqlite database.
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        // HARD STOP. These are fictional accounts with a password published in the repo
        // docs; running this against production would create working logins into a system
        // holding children's health data. There is deliberately no override flag.
        if (app()->environment('production')) {
            throw new \RuntimeException('E2eSeeder must never run in production.');
        }

        User::updateOrCreate(
            ['member_name' => 'admin'],
            [
                'full_name' => 'E2E Administrator',
                'member_email' => 'e2e-admin@example.org',
                'password' => 'AdminPass123!',
                'position' => 0,
                'active' => true,
                'pass_exp_date' => now()->format('Y-m-d'),
            ],
        );
    }
}
