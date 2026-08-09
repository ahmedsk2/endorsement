<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib LV-01's `external` flag. LV-01 names four fields (code/name/order/external); P0c's
 * 2026_08_10_120002_create_levels_and_person_levels.php created `levels` with
 * institution_id/code/name/display_order/active only — the fourth was never built (P1b
 * finding 5). Design §6.1 (as corrected by P1a Task 9) already describes `external` as
 * administrator-owned data after the seed.
 *
 * OWNER DECISION A (2026-08-09, plan 2026-08-09-p1b-structure-admin.md) is why this migration
 * is narrower than the plan's own Task 6 text, which also asked for a `terminal`/graduating
 * marker and a `Level::nextAfter()` "advance one level" inference built on top of it. The
 * owner rejected that inference outright: a wrong terminal marker fails silently in TWO
 * directions — an unmarked top level lets a cohort advance into a level that does not exist,
 * and a wrongly-marked middle level graduates a cohort a year early. Removing the inference
 * removes the whole failure class. P1c's annual promotion stays a single-action, previewed,
 * single-transaction, audited operation, but the OPERATOR chooses the target level explicitly
 * — nothing in this schema encodes "one step up". `EXT` stays outside the ladder and is never
 * promoted regardless.
 *
 * Additive with a false default, on a table holding reference data before the first promotion
 * runs (P1c).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            if (! Schema::hasColumn('levels', 'external')) {
                $table->boolean('external')->default(false)->after('display_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            $table->dropColumn('external');
        });
    }
};
