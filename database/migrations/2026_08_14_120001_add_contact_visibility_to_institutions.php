<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib PE-02 — "contact visibility per policy toggles, logged-in members only".
 *
 * TWO VALUES, not a per-field matrix, and `notes` is on neither side of the toggle:
 *
 *   'admins'   (default) only holders of `people.manage` see a phone number
 *   'members'  any authenticated account holder sees a phone number
 *
 * `notes` stays `people.manage`-only under both. It is free text a supervisor writes ABOUT a
 * named colleague; docs/COMPLIANCE.md already records it as stored in the clear and legible in
 * every backup, with $hidden named as the compensating control. A department cannot opt its way
 * out of that, and a phone number for the on-call list is a different kind of fact.
 *
 * Default 'admins' because Munawib §3 is "privacy by default": a department that wants an open
 * directory says so in one click; a department that discovers its notes were readable cannot
 * un-read them.
 *
 * Additive and defaulted, on a table holding one real row per deployment (D11). This is NOT a
 * calendar column — `Calendar::settings()`'s memo carries only the six calendar values, so a
 * write here leaves nothing stale for `Calendar::flush()` to clear. See
 * `CalendarWritersFlushTest`'s allow-list entry for App\Support\ContactVisibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            if (! Schema::hasColumn('institutions', 'contact_visibility')) {
                $table->string('contact_visibility', 20)->default('admins')->after('active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn('contact_visibility');
        });
    }
};
