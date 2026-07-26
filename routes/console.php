<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Spec §10.2 — handover reminders fire shortly after each handover time (Asia/Riyadh,
 * the app timezone). The command is idempotent per (unit, date, slot), so the extra
 * safety run every 15 minutes only catches a missed schedule tick — it can never
 * double-send.
 */
foreach (config('endorsement.handover_times', []) as $time) {
    $fireAt = Carbon::parse($time)
        ->addMinutes((int) config('endorsement.remind_delay_minutes'))
        ->format('H:i');

    Schedule::command('endorsement:remind')->dailyAt($fireAt);
}

Schedule::command('endorsement:remind')->everyFifteenMinutes();

/*
 * Security & data-lifecycle jobs (docs/COMPLIANCE.md).
 *
 * The audit chain is only tamper-EVIDENT if something actually checks it, so it is
 * verified hourly and a failure is escalated rather than logged quietly. The nightly
 * backup runs before the retention sweep, so anything the sweep removes was captured
 * first. Retention only ever touches expired operational rows — never clinical records.
 */
Schedule::command('audit:verify')
    ->hourly()
    ->onFailure(function (): void {
        \App\Support\OpsAlert::critical('Audit chain verification FAILED', 'The append-only trail may have been altered. Investigate immediately.');
    });

Schedule::command('backup:run')
    ->dailyAt('01:30')
    ->onFailure(function (): void {
        \App\Support\OpsAlert::critical('Nightly backup FAILED', 'No recoverable copy of the clinical record was produced tonight.');
    });

// Detection, as distinct from integrity: audit:verify proves the chain is intact, which
// is a different question from whether anything alarming happened inside it. Every insider
// scenario in the threat model was audited and unwatched.
Schedule::command('audit:anomalies')
    ->hourly()
    ->onFailure(function (): void {
        \App\Support\OpsAlert::critical('Audit anomaly sweep FAILED', 'Suspicious-activity detection did not run.');
    });

Schedule::command('data:retention --force')
    ->dailyAt('02:30')
    ->onFailure(function (): void {
        // This one had no escalation at all: the disposal mechanism PDPL Art. 18 expects
        // could stop running and nothing would say so.
        \App\Support\OpsAlert::critical('Data retention sweep FAILED', 'Expired operational rows were not disposed of.');
    });
