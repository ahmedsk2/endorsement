<?php

namespace App\Console\Commands;

use App\Support\Demo\DemoDepartment;
use App\Support\Demo\DemoLedger;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Munawib ST-05's *"and for development fixtures"* half: create the demo department from a terminal
 * (P1e Task 13).
 *
 * DELIBERATELY THIN. Every decision — whether one already exists, what gets built, the transaction,
 * the single audit row — lives in `App\Support\Demo\DemoDepartment`, so this command and the screen
 * (Task 14) cannot drift into two behaviours sharing one name. That is the same call
 * `PeriodController` makes about `PeriodGenerator` and `E2eSeeder` makes about `ClinicWriter`.
 *
 * NO ENVIRONMENT GUARD, unlike `DemoSeeder` and `E2eSeeder` (P1e Decision F). Those two mark
 * nothing they write and have no removal path at all, so a production run is permanent and both are
 * right to throw. This one is ledgered and provably removable — `DemoRoundTripTest` compares every
 * table in the schema around a create-and-remove — and it mints no account, so it creates no way
 * into a system holding children's health data. `DemoSeeder` is untouched and keeps its throw.
 */
class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed {--force : Skip the confirmation prompt}';

    protected $description = 'Create the clearly-labelled, removable demo department (Munawib ST-05).';

    public function handle(): int
    {
        // Asked here as well as inside the writer, because a command that prompts and THEN refuses
        // has wasted the operator's decision. The writer's own refusal is still the guarantee.
        if (DemoLedger::has()) {
            $this->error('A demo department already exists. Run `php artisan demo:remove` first.');

            return self::FAILURE;
        }

        if (! $this->option('force')
            && ! $this->confirm('Create a clearly-labelled demo department now?', false)) {
            $this->line('Nothing was created.');

            return self::SUCCESS;
        }

        try {
            $result = DemoDepartment::create();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Created demo department {$result['batch']} — {$result['rows']} rows.");

        foreach ($result['skipped'] as $note) {
            $this->line('  - '.$note);
        }

        $this->line('Remove it with: php artisan demo:remove');

        return self::SUCCESS;
    }
}
