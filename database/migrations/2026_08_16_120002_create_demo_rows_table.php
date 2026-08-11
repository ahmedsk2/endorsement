<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib ST-05's hard word — REMOVABLE (P1e Decision F). `demo_rows` records every row the demo
 * department creates, keyed by (table, row id) and grouped by a batch UUID, so removal can PROVE it
 * removed everything rather than assume it.
 *
 * ---------------------------------------------------------------------------------------------
 * 1. PROVENANCE AS A JOIN, NOT A COLUMN — and the alternative was considered, not overlooked.
 * ---------------------------------------------------------------------------------------------
 * The obvious design is a `demo` boolean on every table the seed writes. It fails four ways:
 *
 *   - Nine tables gain a permanent column for a training feature.
 *   - `units` has no provenance column of ANY kind — not even `institution_id` (see
 *     `2026_08_09_120001`'s docblock, which says so deliberately) — so the scheme cannot even be
 *     applied uniformly.
 *   - A flag is editable to `false` from any database console, after which removal silently misses
 *     the row and reports success.
 *   - Decisively: a column does not ENUMERATE. "Which rows did the demo create?" would still be a
 *     scan of every table, which is precisely the question removal has to answer.
 *
 * A ledger answers it directly. The three precedents this generalises all exist here already:
 * `applied_role_defaults` is a ledger of "this seed step already ran" (and is why
 * `AccessControlSeeder` never re-asserts a revoked default); `handovers.legacy_source_table` +
 * `legacy_id` is provenance as a KEY, the legacy import's idempotency pair; and
 * `person_levels.promotion_batch_id` is a batch UUID with no foreign key, because a batch is not a
 * row anywhere.
 *
 * ---------------------------------------------------------------------------------------------
 * 2. A SECOND `institutions` ROW WAS REJECTED BY THE SCHEMA, NOT BY PREFERENCE.
 * ---------------------------------------------------------------------------------------------
 * "Seed a demo institution and delete by `institution_id`" is forbidden by D11 — that column is
 * provenance and in-instance grouping, never a query filter — and the schema is one-way committed
 * against it: `units.code`, `people.email`, `users.member_name` and
 * `handover_signoffs(unit_id, handover_date)` are institution-blind UNIQUE by design, so a demo
 * unit sharing a real unit's code collides on insert. Worse than not working, it would teach the
 * next reader that filtering a query by that column is acceptable here.
 *
 * (Spelled around rather than shown, deliberately: `InstitutionProvenanceTest` scans migrations as
 * source, prose included, and this paragraph originally FAILED THE BUILD on its own illustration of
 * the rule — measured, not anticipated. The guard is right that a migration naming that clause
 * deserves a look, so the reasoning stays and the clause goes.)
 *
 * So THIS TABLE CARRIES NO SUCH COLUMN AT ALL, and none of its keys could lead with one.
 *
 * ---------------------------------------------------------------------------------------------
 * 3. NO FOREIGN KEY ON `row_id`, AND THAT IS NOT AN OVERSIGHT.
 * ---------------------------------------------------------------------------------------------
 * It points at nine different tables depending on `table_name`; no relational constraint can
 * express "whatever `table_name` says". `person_levels.promotion_batch_id` carries no key for the
 * same species of reason. The consequence is stated plainly: a demo row hard-deleted outside the
 * removal path leaves a ledger entry naming nothing, which removal treats as already-gone rather
 * than as an error — the ledger is a record of what was CREATED, and a row that is no longer there
 * needs no removing.
 *
 * ---------------------------------------------------------------------------------------------
 * 4. NO SOFT DELETE, AND `created_at` ALONE.
 * ---------------------------------------------------------------------------------------------
 * A ledger row is written once and deleted once; it is never edited, so an `updated_at` would be a
 * column that can only ever hold the value `created_at` already holds. And tombstoning a ledger
 * would mean removal had to filter out its own history on every read — the ledger is bookkeeping,
 * not clinical history, and the hash-chained `audit_log` records that a demo department was created
 * and removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_rows', function (Blueprint $table) {
            $table->id();

            // Which run of the demo seed created this row. Minted by the creator, never by this
            // table, and carrying no foreign key: a batch is not a row anywhere — the exact call
            // `person_levels.promotion_batch_id` already makes.
            $table->uuid('batch_id');

            // 64 is MySQL's own identifier limit, so it cannot be too short for any table this
            // schema could ever contain.
            $table->string('table_name', 64);

            // Matches `$table->id()`'s bigint unsigned on every table this can name.
            $table->unsignedBigInteger('row_id');

            $table->timestamp('created_at')->nullable();

            // THE IDENTITY OF A LEDGERED ROW, and the one constraint that matters: a row cannot
            // belong to two batches, because "which batch removes it" would then have no honest
            // answer. It doubles as the lookup Task 13's pre-flight performs per inbound foreign
            // key — "is the row this real record points at a demo row?" — which is a
            // (table_name, row_id) question and nothing else. Led by the columns actually compared
            // on. A separate index on `table_name` alone would be dead weight: this key's leading
            // column already serves a prefix lookup.
            $table->unique(['table_name', 'row_id']);

            // Every read of this table filters or groups by the batch: listing a batch's rows for
            // removal, forgetting a batch afterwards, and asking which batches exist. Not part of
            // the unique above, deliberately — including it there would let one row be ledgered
            // twice under two batches, which is the state the unique exists to make impossible.
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_rows');
    }
};
