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
    /**
     * A GUARD MUST COVER ONE STATEMENT, NOT ONE BLOCK — measured on MySQL 8.4, 2026-08-12
     * (docs/REHEARSAL-MYSQL-2026-08-12.md §5.6).
     *
     * MySQL has no transactional DDL, so a migration can stop between any two statements it
     * emits, and `hasColumn` guards exist so the retry picks up where the previous attempt
     * stopped. This migration emits FIVE statements, not three — `->index()` is a separate
     * `alter table … add index` and `->constrained()` a separate `alter table … add constraint`:
     *
     *   1. alter table `person_levels` add `promotion_batch_id` char(36) null after `effective_to`
     *   2. alter table `person_levels` add index `person_levels_promotion_batch_id_index`(…)
     *   3. alter table `person_levels` add `reason` varchar(255) null after `promotion_batch_id`
     *   4. alter table `person_levels` add `created_by` bigint unsigned null after `reason`
     *   5. alter table `person_levels` add constraint `person_levels_created_by_foreign` …
     *
     * Written as one `hasColumn` per BLOCK, a failure landing between 1 and 2 (or 4 and 5) left
     * the column present, so the retry skipped the whole block, recorded the migration as Ran and
     * **the index — or the foreign key — was silently missing for ever, with no error anywhere**.
     * Reproduced: `migrate` reported the migration DONE, restored `reason`, `created_by` and its
     * constraint, and left `person_levels_promotion_batch_id_index` absent.
     *
     * So the index and the constraint carry their own existence checks. All three checks read
     * pre-migration state, which is correct because the three objects are independent. On a
     * database where this migration already ran, every check is false and this is a no-op.
     */
    public function up(): void
    {
        Schema::table('person_levels', function (Blueprint $table) {
            if (! Schema::hasColumn('person_levels', 'promotion_batch_id')) {
                $table->uuid('promotion_batch_id')->nullable()->after('effective_to');
            }
        });

        Schema::table('person_levels', function (Blueprint $table) {
            if (! Schema::hasIndex('person_levels', 'person_levels_promotion_batch_id_index')) {
                $table->index('promotion_batch_id', 'person_levels_promotion_batch_id_index');
            }
        });

        Schema::table('person_levels', function (Blueprint $table) {
            if (! Schema::hasColumn('person_levels', 'reason')) {
                $table->string('reason', 255)->nullable()->after('promotion_batch_id');
            }
        });

        Schema::table('person_levels', function (Blueprint $table) {
            if (! Schema::hasColumn('person_levels', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('reason');
            }
        });

        Schema::table('person_levels', function (Blueprint $table) {
            $hasForeignKey = collect(Schema::getForeignKeys('person_levels'))
                ->contains(fn (array $key) => in_array('created_by', $key['columns'], true));

            if (! $hasForeignKey) {
                $table->foreign('created_by', 'person_levels_created_by_foreign')
                    ->references('id')->on('users')->nullOnDelete();
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
