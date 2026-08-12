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
        // Constraint, then index, then column — the same cross-engine order as
        // 2026_08_10_120001's `down()`, and for the same two reasons. `up()` adds an explicit
        // `index('person_id')` on top of the foreign key, and `dropConstrainedForeignId()` does
        // not remove it, so on SQLite the column drop fails with
        // `error in index invitations_person_id_index after drop column`. Measured 2026-08-12
        // (docs/REHEARSAL-UPGRADE-2026-08-12.md).
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
            $table->dropIndex(['person_id']);
            $table->dropColumn('person_id');
        });
    }
};
