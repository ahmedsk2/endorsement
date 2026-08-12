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
 * indexed because it is how a reader groups the per-person `person_level_change` audit rows
 * (`PromotionController::commit()`) back into one act.
 *
 * CORRECTED (review minor 14): this column does NOT, on its own, answer "show me everything
 * that promotion did" — it answers "show me the spans this batch OPENED". `App\Support\
 * LevelAssignment::assign()` stamps it only on the NEW span it creates, never on the PRIOR span
 * it closes (that row keeps whatever batch id — or none — opened IT); and `Promotion::commit()`'s
 * retire path (Decision D, no target level) stamps nothing at all, because `LevelAssignment::
 * close()` only closes a span, and closing is not opening. Both are deliberate, not oversights —
 * `LevelAssignment::close()`'s own docblock explains why: overwriting a closed span's
 * `promotion_batch_id`/`reason`/`created_by` would misattribute whoever ORIGINALLY opened it to
 * whoever is retiring the cohort today. The query this column DOES answer completely is "which
 * spans did this batch open" (`where promotion_batch_id = ?`); "everything this batch did",
 * including every closure and every retirement, is what the per-person `person_level_change`
 * audit rows exist for (Decision H) — join through their own `detail`'s `batch=` value, not this
 * column, for the full picture.
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

        // `promotion_batch_id` was added `->index()`, and SQLite will not drop a column while an
        // index still names it (`error in index person_levels_promotion_batch_id_index after drop
        // column`). MySQL drops the index with the column and does not need this line; it is
        // harmless there. Measured 2026-08-12 (docs/REHEARSAL-UPGRADE-2026-08-12.md).
        Schema::table('person_levels', function (Blueprint $table) {
            $table->dropIndex(['promotion_batch_id']);
            $table->dropColumn(['promotion_batch_id', 'reason']);
        });
    }
};
