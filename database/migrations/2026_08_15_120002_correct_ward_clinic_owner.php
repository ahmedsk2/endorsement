<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a two-writer disagreement over Owner Decision B (2026-08-09, "WARD is the sole
 * clinic owner"): `ReferenceSeeder` has always written `clinic_owner => true` for WARD on
 * COLD START, but `2026_08_13_120001_add_munawib_configuration_to_units`'s backfill — the
 * path an UPGRADE takes, for a `units` row that already existed — left `clinic_owner` false
 * for every seeded code including WARD (see that migration's corrected docblock). Once a
 * `units` row exists with the wrong value, `ReferenceSeeder` cannot fix it: unit profile
 * columns are written on CREATE only, by design, so an administrator's later configuration
 * is never silently reverted by a re-seed — which also means `php artisan db:seed --force`
 * is NOT a remedy here. Verified empirically: cold start -> WARD.clinic_owner = 1; upgrade
 * -> 0, and still 0 after `db:seed --force`.
 *
 * DELIBERATELY A SEPARATE MIGRATION rather than relying on fixing 120001's backfill alone.
 * Laravel migrations do not re-run once recorded in the `migrations` table, so an
 * environment that already applied 120001 — including the MySQL 8.4 rehearsal stack that
 * found this defect, and possibly other pre-production databases — would otherwise carry
 * the wrong value forever, with no migration left to correct it. This one is an idempotent,
 * additive UPDATE targeting the one row that needs it: a no-op on a database where WARD is
 * already correct (a cold start, or a fresh migrate chain that never saw the old backfill),
 * and a fix everywhere else. No column is added, retyped, or dropped, and `training_rotation`
 * / `call_target` are untouched — 120001 already backfills those correctly for all four
 * units and nothing here overlaps with that.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('units')->where('code', 'WARD')->update(['clinic_owner' => true]);
    }

    public function down(): void
    {
        // Deliberately a no-op. `clinic_owner = true` for WARD is Owner Decision B — current
        // and correct, not a schema shape this migration introduced. Reverting it to false
        // would just reintroduce the defect this migration exists to fix.
    }
};
