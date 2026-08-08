<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib AR-08 / ST-01: the per-department calendar configuration `App\Support\Calendar`
 * reads. Additive and nullable-or-defaulted throughout; `institutions` holds one real row per
 * deployment (D11), so this cannot be a slow migration.
 *
 * DELIBERATELY ABSENT: `timezone`. Owner decision 3 (2026-08-08) puts the timezone on the
 * INSTANCE (`APP_TIMEZONE`, config/app.php:73) and only `hijri_offset_days` on the DEPARTMENT.
 * A per-department timezone column beside the env var would be one fact in two places, and it
 * would make the handover day boundary — UNIQUE(unit_id, handover_date), uncorrectable after
 * the first clinical write per docs/RUNBOOK-PROVISION.md:25 — editable from a screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            // Whether Hijri dates are shown at all (UX-04). Display only — never storage,
            // never a query key, never an audit value.
            $table->boolean('hijri_enabled')->default(true)->after('active');

            // The signed calibration applied to algorithmic Umm al-Qura conversion, verified
            // against the department's OWN published calendar. 0 for a new customer: an
            // uncalibrated offset invented on that department's behalf would be a guess
            // rendered as fact on every screen. QCH sets -1 via HIJRI_OFFSET_DAYS.
            $table->smallInteger('hijri_offset_days')->default(0)->after('hijri_enabled');

            // ISO-8601 weekday numbers (Mon=1 ... Sun=7). Numbers, not names: names are
            // locale-dependent and this column is compared, not displayed. MySQL cannot carry
            // a literal default on a JSON column, so the default lives on the Eloquent model
            // ($attributes) for rows created after this migration, and the backfill below
            // covers whatever row already existed when it ran.
            $table->json('weekend_days')->nullable()->after('hijri_offset_days');

            // MR-01: 'months' or 'week_blocks'.
            $table->string('period_type', 20)->default('week_blocks')->after('weekend_days');

            // MR-01: block lengths in weeks, in order, one entry per block. Lengths MAY vary
            // within a year — QCH is thirteen blocks, the last one absorbing whatever weeks
            // remain before the next academic year's start date (owner decision 4). This
            // column stores the department's NOMINAL plan; the period generator (P1d) is what
            // computes the actual last-block length against academic_year_start, it is not
            // read literally as gospel. Ignored entirely when period_type is 'months'.
            $table->json('block_weeks')->nullable()->after('period_type');

            // The first day of the current academic year. Department-set (MR-01); null until
            // the setup wizard or the settings screen supplies it.
            $table->date('academic_year_start')->nullable()->after('block_weeks');
        });

        // Defaults for the JSON columns, which cannot carry one in MySQL. Only touches a row
        // that already existed when this migration ran (whereNull matches nothing on a fresh
        // install) — the Eloquent model default covers every row created afterwards.
        \DB::table('institutions')->whereNull('weekend_days')->update([
            'weekend_days' => json_encode([5, 6]),      // Friday, Saturday
            'block_weeks' => json_encode([4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 5]),
        ]);
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn([
                'hijri_enabled', 'hijri_offset_days', 'weekend_days',
                'period_type', 'block_weeks', 'academic_year_start',
            ]);
        });
    }
};
