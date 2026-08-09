<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib LV-03/LV-04 provenance. P1 finding 9: a promotion is not addressable or reversible as
 * a unit, and "this cohort advanced on this date" cannot be rendered or undone, because
 * `person_levels` records only (person, level, from, to).
 *
 * THIS MUST LAND BEFORE THE FIRST PROMOTION. Today it is additive and free: no screen has ever
 * written this table and no production row exists. After one promotion has run it is a backfill
 * of facts nobody recorded, which is a different and much worse migration.
 *
 * `created_by` is `users`, not `people`: this records the ACTOR, and actors are accounts — the
 * same distinction `handover_signoffs` draws between its four `*_person_id` names of record and
 * its `signed_off_by_user_id`/`reopened_by_user_id` actors. `people.id` and `users.id` are
 * independent sequences; never move an id between them without joining through
 * `users.person_id`.
 *
 * `promotion_batch_id` is a UUID string, not an FK: a batch is not a row anywhere. It is
 * indexed because "show me everything that promotion did" is the query LV-03's undo would need,
 * and because it is how a reader groups the per-person audit rows back into one act.
 *
 * NO overlap constraint is added at the database level. SQLite cannot express it, and a partial
 * unique index on MySQL 8.4 would not either. The guarantee lives in App\Support\LevelAssignment,
 * which `PersonLevelsHaveOneWriterTest` proves is the only writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('person_levels', function (Blueprint $table) {
            if (! Schema::hasColumn('person_levels', 'promotion_batch_id')) {
                $table->uuid('promotion_batch_id')->nullable()->after('effective_to')->index();
            }
        });

        Schema::table('person_levels', function (Blueprint $table) {
            if (! Schema::hasColumn('person_levels', 'reason')) {
                $table->string('reason', 255)->nullable()->after('promotion_batch_id');
            }
        });

        Schema::table('person_levels', function (Blueprint $table) {
            if (! Schema::hasColumn('person_levels', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('reason')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('person_levels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('person_levels', function (Blueprint $table) {
            $table->dropColumn(['promotion_batch_id', 'reason']);
        });
    }
};
