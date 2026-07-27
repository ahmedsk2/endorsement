<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot WHO signed the day, alongside the FK that already records it.
 *
 * The endorser names were frozen at write time from the start, precisely so a later rename
 * could not rewrite a signed sheet. The signing clinician's own name was not: it was
 * resolved live through `HandoverSignoff::signedOffBy()`, a plain belongsTo against a
 * SoftDeletes User. So on any historical print, a rename rewrote "Signed off … by X", and a
 * deactivated account — and this system deactivates rather than deletes, by rule — made the
 * attribution disappear entirely, because the print template hides the line when the name
 * is null.
 *
 * That was cosmetic while every named clinician's signature appeared beside it. Under the
 * 2026-07-27 signature ruling it is not: wherever a signature is withheld, this line is the
 * whole attestation of who documented the handover.
 *
 * Additive and nullable, per the project rule. Existing rows keep resolving through the
 * relation (the payload falls back to it), so nothing is backfilled and no history moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('handover_signoffs', function (Blueprint $table) {
            $table->string('signed_off_by_name')->nullable()->after('signed_off_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('handover_signoffs', function (Blueprint $table) {
            $table->dropColumn('signed_off_by_name');
        });
    }
};
