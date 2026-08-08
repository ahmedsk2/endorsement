<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invitation is issued TO A PERSON (P0c/Task 8), matched-or-created by normalized email at
 * issue time. `member_email` and `position` stay on this row unchanged: they are the FROZEN
 * terms of this particular invitation, and a rostered person's details can change between issue
 * and redemption without silently changing what was offered.
 *
 * Nullable: rows issued before P0c have no person. `Invitation::redeemable()`'s query is
 * unchanged; `InvitationAcceptController::store()` treats a null `person_id` the same way it
 * always has (create a new person at redemption time) for those legacy rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->after('institution_id')
                ->constrained('people')->nullOnDelete();
            $table->index('person_id');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('person_id');
        });
    }
};
