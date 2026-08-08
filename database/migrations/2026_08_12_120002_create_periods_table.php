<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib MR-01 (owner decision 2): both period systems are first-class. A row is either a
 * calendar month or a week-measured block; `kind` is stored per row (not read from the
 * institution's CURRENT `period_type`) so a department that switches systems mid-year does not
 * silently relabel periods it already scheduled against.
 *
 * OWNER DECISION 4 (2026-08-08, round 2): the academic year resets to a fixed start date each
 * year rather than drifting, so the final week-block absorbs whatever remains before the next
 * year's start — its length varies year to year and is never hardcoded. See
 * `App\Support\PeriodGenerator::weekBlocks()`.
 *
 * `institution_id` is carried for provenance/in-instance grouping only (D11) — it is NEVER part
 * of a query filter (`InstitutionProvenanceTest::test_no_query_filters_on_institution_id` is the
 * source-level guard) and, for the same reason every other compound unique index in this schema
 * omits it (`people.short_name`, `levels.code`, `handover_signoffs(unit_id, handover_date)`),
 * it is NOT part of the unique key below either. D11 makes one database one customer, so a
 * plain UNIQUE on (academic_year, position) is both honest and enforceable — a composite
 * including institution_id would be toothless for exactly the bootstrap/fixture rows where it
 * is NULL, and would invite a future `where('institution_id', ...)` "to match the index".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();

            // e.g. "2026-2027". Department-set, not computed: finding 7 means a run need not
            // align with a Gregorian year, and the department names its own years.
            $table->string('academic_year', 20);

            // 'month' | 'week_block' (App\Models\Period::MONTH / ::WEEK_BLOCK).
            $table->string('kind', 20);

            $table->unsignedSmallInteger('position');       // 1-based; "Block 11" is position 11
            $table->string('label');                        // "Block 11" / "August 2026"
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            // 'position', not 'index' — index is a reserved word in MySQL and a Blueprint method.
            // Institution-blind by design — see the docblock above.
            $table->unique(['academic_year', 'position'], 'periods_year_position_unique');
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periods');
    }
};
