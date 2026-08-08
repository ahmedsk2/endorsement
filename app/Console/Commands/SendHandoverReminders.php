<?php

namespace App\Console\Commands;

use App\Models\HandoverSignoff;
use App\Models\Unit;
use App\Support\Push\PushSender;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Spec §10.2 — the handover-time reminder. For each unit: if today has no SIGNED
 * endorsement once a handover time (+ configured delay) has passed, push a reminder to
 * that unit's opted-in users. Payloads carry unit + date + status ONLY — never patient
 * data. Idempotent per (unit, date, slot) via a cache marker, so the scheduler can call
 * it as often as it likes.
 */
class SendHandoverReminders extends Command
{
    protected $signature = 'endorsement:remind';

    protected $description = 'Push reminders for units with no signed endorsement after a handover time';

    public function handle(PushSender $sender): int
    {
        $slot = $this->currentSlot();

        if ($slot === null) {
            $this->info('No reminder window is open right now.');

            return self::SUCCESS;
        }

        $today = now()->format('Y-m-d');
        $pushed = 0;

        foreach (Unit::codes() as $code) {
            $unit = Unit::where('code', $code)->first();

            if ($unit === null) {
                continue;
            }

            $signed = HandoverSignoff::where('unit_id', $unit->id)
                ->whereDate('handover_date', $today)
                ->whereNotNull('signed_off_at')
                ->exists();

            if ($signed) {
                continue;
            }

            // One reminder per unit-day-slot, however often the scheduler runs us.
            $marker = "endorsement.reminded.{$unit->id}.{$today}.{$slot}";

            if (! Cache::add($marker, true, now()->addHours(20))) {
                continue;
            }

            $payload = [
                'title' => "{$unit->code} endorsement",
                'body' => "No signed endorsement for {$today} yet.",
                'url' => '/endorsement/'.strtolower($unit->code).'/'.$today,
            ];

            $subscriptions = \App\Models\PushSubscription::query()
                ->whereIn('user_id', function ($q) use ($unit) {
                    $q->select('user_id')->from('reminder_preferences')->where('unit_id', $unit->id);
                })
                ->whereHas('user', fn ($q) => $q->where('active', true))
                ->get();

            foreach ($subscriptions as $subscription) {
                $sender->send($subscription, $payload);
                $pushed++;
            }
        }

        $this->info("Slot {$slot}: {$pushed} reminder(s) pushed.");

        return self::SUCCESS;
    }

    /**
     * The most recent handover time whose reminder window (time + delay) has opened
     * today, or null when none has.
     */
    private function currentSlot(): ?string
    {
        $delay = (int) config('endorsement.remind_delay_minutes');
        $open = null;

        foreach (config('endorsement.handover_times', []) as $time) {
            $fires = Carbon::parse(now()->format('Y-m-d').' '.$time)->addMinutes($delay);

            if (now()->greaterThanOrEqualTo($fires)) {
                $open = $time;
            }
        }

        return $open;
    }
}
