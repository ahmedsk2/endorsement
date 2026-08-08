<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib §30 `holidays/{holId} { name, rule:{greg?|hijri?}, equityTracked }`. The CRUD screen
 * is P1b; this table and `App\Support\Calendar`'s resolver are P1a's job.
 *
 * A HIJRI-ruled holiday resolves THROUGH the department's `hijri_offset_days` calibration
 * (`institutions.hijri_offset_days`, Task 1), never around it — `Calendar::hijri()`'s own
 * docblock explains why the offset is applied to the Gregorian instant before conversion, and
 * a holiday anchored in Hijri terms must move with everything else when a department
 * recalibrates rather than acquiring a second, silently divergent calendar.
 *
 * `institution_id` is provenance/in-instance grouping only (D11) — same convention as
 * `periods.institution_id` (2026_08_12_120002): NEVER a query filter
 * (`InstitutionProvenanceTest::test_no_query_filters_on_institution_id`), and therefore not
 * part of any unique index either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');

            // 'gregorian' | 'hijri' (App\Models\Holiday::GREGORIAN / ::HIJRI). Decides which
            // calendar `month`/`day`/`year` below are expressed in.
            $table->string('calendar', 20);

            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');

            // null = recurs every year. When set, compared against the SAME calendar as `calendar`
            // (a Hijri rule's year is a Hijri year, a Gregorian rule's a Gregorian one) — the rule
            // is defined in one calendar throughout, never a Gregorian year pinned to a Hijri
            // month/day.
            $table->unsignedSmallInteger('year')->nullable();

            // How many days the holiday spans, counted forward in GREGORIAN days from the
            // resolved anchor date — never in units of the rule's own calendar, so a span that
            // crosses a Hijri month end (e.g. the run into Eid al-Fitr) needs no special case.
            $table->unsignedTinyInteger('duration_days')->default(1);

            // Munawib HE-*: whether working this holiday counts toward holiday equity (Stage 4).
            // Stored now so the Stage 1 screen captures it once rather than asking again later.
            $table->boolean('equity_tracked')->default(true);

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'calendar', 'month', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
