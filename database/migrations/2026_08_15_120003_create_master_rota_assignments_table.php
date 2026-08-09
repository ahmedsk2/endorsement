<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib MR-02: a grid of people × periods, one unit per person per period, with split periods as
 * date-bounded sub-assignments.
 *
 * EVERY ROW IS A SPAN. `starts_on`/`ends_on` are NOT NULL, both bounds inclusive — the idiom
 * `Person::levelAt()` and `Period::contains()` already share. A whole-period assignment is the
 * degenerate split: exactly one row whose bounds equal its period's. There is deliberately no
 * nullable range meaning "the whole period", because that gives one fact two representations, and
 * no parent/child span pair, because a parent row for a split period has no correct `unit_id`.
 *
 * MR-02's "one unit per person per period" is therefore an invariant on the SET, not a unique
 * index: the rows for one (person, period) must not overlap, and each must lie wholly inside its
 * period. `App\Models\MasterRotaAssignment::booted()` enforces both — modelled directly on
 * `Period::booted()`, which refuses overlapping periods for the same reason (one person on two
 * units on one day is a state the grid cannot render and MR-04's future call roster cannot
 * resolve). `App\Support\Rota\RotaAssignment` will be the only writer once it lands.
 *
 * A UNIQUE index cannot express any of this — SQLite has no exclusion constraint and MySQL 8.4 has
 * no range type — so the guarantee lives in the model, exactly as `person_levels`' overlap rule
 * lives in `App\Support\LevelAssignment`.
 *
 * NO SOFT DELETE, deliberately (P1d Decision E). This is schedule structure, not a clinical row:
 * the grid's primary interaction is re-editing the same cell while a year is planned, and
 * tombstoning every superseded span would put a `whereNull('deleted_at')` on every read path for
 * history the hash-chained `audit_log` already holds, unedited and unerasable.
 *
 * `institution_id` is provenance and in-instance grouping only (D11). It is never a query filter
 * and never part of a key — `InstitutionProvenanceTest` guards both, and its index/unique pattern
 * has no allow-list at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_rota_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();

            // cascadeOnDelete matches `person_levels`: people are SOFT-deleted (owner ruling), so
            // this FK never fires in practice; it exists so a hard delete in a future data-repair
            // script cannot leave an assignment pointing at nobody.
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete, NOT cascade: `PeriodController::destroy()` HARD-deletes an
            // academic year's periods, and a cascade there would silently take a department's
            // whole planned rota with it. The controller refuses the delete while any assignment
            // references the year; this constraint is the database's own last line behind that.
            $table->foreignId('period_id')->constrained()->restrictOnDelete();

            // restrictOnDelete: units are RETIRED (`active = false`), never deleted (UN-04).
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();

            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            $table->index(['person_id', 'period_id']);
            $table->index(['starts_on', 'ends_on']);
            $table->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_rota_assignments');
    }
};
