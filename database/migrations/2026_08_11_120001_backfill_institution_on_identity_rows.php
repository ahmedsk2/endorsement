<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D11/finding 9: nothing in app/ ever set `institution_id`, so it was NULL on every
 * application-written `people`/`users` row while `LegacyImport` stamped a real id on every
 * imported row — non-null history, null present, worse than uniformly null. `user:create-admin`
 * now gives the column its one writer going forward (Task 4); this migration backfills the
 * accounts that already exist on an instance upgrading into this change.
 *
 * Additive and non-destructive: it sets `institution_id` on `people` and `users` rows where it
 * is currently NULL, and ONLY when the `institutions` table holds exactly one active row. Zero
 * institutions (unseeded) or two or more (no right answer — stamping provenance with a guess is
 * worse than leaving it null) and it makes no change and says nothing further.
 *
 * Deliberately does NOT touch `handovers`, `handover_signoffs`, `invitations` or `levels` —
 * those are clinical or issued rows whose provenance is whatever it was at the time; rewriting
 * it retrospectively would be inventing history. New rows inherit correctly from the actor once
 * the accounts carry the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('institutions')->where('active', true)->limit(2)->pluck('id');

        // Exactly one, or do nothing.
        if ($ids->count() !== 1) {
            return;
        }

        $id = (int) $ids->first();

        DB::table('people')->whereNull('institution_id')->update(['institution_id' => $id]);
        DB::table('users')->whereNull('institution_id')->update(['institution_id' => $id]);
    }

    public function down(): void
    {
        // Nothing to restore: this only filled nulls, and which rows were null is not recorded.
        // Re-running up() is a no-op, which is the property that matters.
    }
};
