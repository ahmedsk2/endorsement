<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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
