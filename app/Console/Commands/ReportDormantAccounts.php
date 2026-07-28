<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\OpsAlert;
use Illuminate\Console\Command;

/**
 * Active accounts nobody has signed into for a long time.
 *
 * The leaver problem, backwards. This system cannot be told when a clinician leaves — that
 * is organisational, and the agreed process is a review of the active-account list. But the
 * review happens ANNUALLY, which means an account belonging to someone who left can stay
 * live for up to twelve months, and in a department with rotating residents that is not a
 * hypothetical.
 *
 * So the system watches instead of waiting to be asked. It cannot know who left — but it
 * knows who has not been here, and "active, and untouched for three months" is the shape a
 * departed clinician's account has. It is a prompt to look, never an automatic deactivation:
 * a doctor returning from long leave to find their account disabled is a worse failure than
 * the one being prevented, and this system does not get to make that decision alone.
 *
 * IDS AND COUNTS ONLY in the alert. It travels by email to an external mailbox, and a list
 * of who is absent from a children's hospital is not something to put in one.
 */
class ReportDormantAccounts extends Command
{
    protected $signature = 'users:dormant {--days=90 : Treat an account as dormant after this many days}';

    protected $description = 'Report active accounts that have not signed in for a long time';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        // `last_login_at` is null for an account that has never signed in at all. Those are
        // judged from creation instead — an invitation accepted and then never used is the
        // same concern, and arguably a sharper one.
        $dormant = User::query()
            ->where('active', true)
            ->where(function ($q) use ($cutoff) {
                $q->where('last_login_at', '<', $cutoff)
                    ->orWhere(fn ($q2) => $q2->whereNull('last_login_at')->where('created_at', '<', $cutoff));
            })
            ->orderBy('id')
            ->get(['id', 'last_login_at', 'created_at']);

        if ($dormant->isEmpty()) {
            $this->info("No active account has been idle for {$days} days or more.");

            return self::SUCCESS;
        }

        $lines = $dormant->map(function (User $u) {
            $idle = ($u->last_login_at ?? $u->created_at)->diffInDays(now());

            return $u->last_login_at === null
                ? "user={$u->id} has NEVER signed in ({$idle} days since the account was created)"
                : "user={$u->id} last signed in {$idle} days ago";
        })->all();

        foreach ($lines as $line) {
            $this->warn($line);
        }

        OpsAlert::critical(
            $dormant->count().' account(s) active but idle for '.$days.'+ days',
            implode("\n", $lines)
                ."\n\nThese may belong to people who have left. Check them in Admin → Users and "
                ."deactivate any that should no longer have access. Accounts are deactivated, "
                ."never deleted, so attribution on past handovers is preserved.",
        );

        // NOT a failure exit. The command did its job; a non-zero code would make the
        // scheduler's onFailure handler fire and report the same thing a second time.
        return self::SUCCESS;
    }
}
