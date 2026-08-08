<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-unit variation moves out of the hardcoded App\Support\UnitProfile registry and into
 * data (design §6.1), so a department is configuration rather than code.
 *
 * Additive and defaulted, per the project rule. The four paediatric units are BACKFILLED
 * here rather than left to a seeder: the owner's production database must arrive at the
 * right values from a migration alone, since seeders are not run against it. Under
 * RefreshDatabase the units table is empty, so this backfill is a no-op in the test suite —
 * ReferenceSeeder is what the tests exercise.
 */
return new class extends Migration
{
    /**
     * The historical UnitProfile registry, verbatim — this is the data being moved. String
     * keys (matching the column names) rather than a positional array: `bar_class` and
     * `print_plan_label` are adjacent strings, and a transposed pair of indices would put a
     * label into a CSS class with nothing to catch it.
     */
    private const PROFILES = [
        'PICU' => [
            'display_order' => 1,
            'active' => true,
            'extra_row_fields' => '[]',
            'bed_label' => 'Bed',
            'consultant_pair' => true,
            'consultant_by_label' => 'Consultant covering',
            'bar_class' => 'channel-bar-picu',
            'print_plan_label' => 'Plan Of Care',
            'print_narrative_label' => 'New events',
        ],
        'NICU' => [
            'display_order' => 2,
            'active' => true,
            'extra_row_fields' => '["dob"]',
            'bed_label' => 'Bed',
            'consultant_pair' => true,
            'consultant_by_label' => 'Consultant covering',
            'bar_class' => 'channel-bar-nicu',
            'print_plan_label' => 'Plan Of Care',
            'print_narrative_label' => 'To be followed',
        ],
        'SCBU' => [
            'display_order' => 3,
            'active' => true,
            'extra_row_fields' => '["dob"]',
            'bed_label' => 'Bed',
            'consultant_pair' => true,
            'consultant_by_label' => 'Consultant covering',
            'bar_class' => 'channel-bar-scbu',
            'print_plan_label' => 'Plan Of Care',
            'print_narrative_label' => 'To be followed',
        ],
        'WARD' => [
            'display_order' => 4,
            'active' => true,
            'extra_row_fields' => '["age","ward_unit"]',
            'bed_label' => 'Room',
            'consultant_pair' => false,
            'consultant_by_label' => 'Consultant Oncall',
            'bar_class' => 'channel-bar-ward',
            'print_plan_label' => 'Management',
            'print_narrative_label' => 'To be followed',
        ],
    ];

    public function up(): void
    {
        // MySQL cannot roll back DDL, and the backfill below runs outside any transaction.
        // Blueprint::addImpliedCommands() maps each ColumnDefinition to its own `add` command,
        // so this closure emits NINE separate ALTER TABLE statements, not one — a run that dies
        // partway through (lock wait timeout, deploy timeout, dropped connection) can leave any
        // prefix of these columns present. Guarding on just the first column only protects
        // against a re-run when the whole block already landed; a re-run after a partial
        // failure would then skip the rest of the block and the backfill below would fail on a
        // missing column. So every column is guarded individually — each `hasColumn` check
        // reads pre-migration state, which is correct because each column is independent — and
        // a re-run picks up wherever the previous attempt actually stopped, never re-adding a
        // column that already exists ("Duplicate column name") and never skipping one that
        // doesn't. That is what keeps the owner from hand-repairing a clinical database.
        Schema::table('units', function (Blueprint $table) {
            // Default HIGH, not 0: seeded units occupy 1-4, and any unit created outside
            // the seeder — which is the entire point of this change — must sort AFTER
            // them until an administrator gives it a real position, not ahead of PICU.
            if (! Schema::hasColumn('units', 'display_order')) {
                $table->unsignedSmallInteger('display_order')->default(1000)->after('name');
            }
            // Retirement is now explicit. The spec requires retired unit codes to 404;
            // previously that was expressed by absence from a PHP array. Opt-IN, not
            // opt-out: under the old static registry, "not one of the four" was
            // inexpressible except by absence from the hardcoded array — a row inserted
            // without an explicit flag must not become silently routable the instant it
            // exists. Defaulting to false also matches the `display_order` default above:
            // an unconfigured unit should be inert, not merely low-priority. The backfill
            // below sets `active = true` explicitly for the four paediatric units; a
            // production row this backfill does NOT match (a `code` that doesn't hit the
            // WHERE below) is therefore left inactive — failing closed is deliberate, and
            // the runbook's `bar_class IS NULL` counter-check already surfaces such a row.
            if (! Schema::hasColumn('units', 'active')) {
                $table->boolean('active')->default(false)->after('display_order');
            }
            if (! Schema::hasColumn('units', 'extra_row_fields')) {
                $table->json('extra_row_fields')->nullable()->after('active');
            }
            if (! Schema::hasColumn('units', 'bed_label')) {
                $table->string('bed_label')->default('Bed')->after('extra_row_fields');
            }
            if (! Schema::hasColumn('units', 'consultant_pair')) {
                $table->boolean('consultant_pair')->default(true)->after('bed_label');
            }
            if (! Schema::hasColumn('units', 'consultant_by_label')) {
                $table->string('consultant_by_label')->default('Consultant covering')->after('consultant_pair');
            }
            // Nullable with no default is intentional: App\Support\UnitProfile derives
            // 'channel-bar-'.strtolower($code) as a fallback when this is NULL. Adding a
            // default here would defeat that fallback for any unit the backfill missed.
            if (! Schema::hasColumn('units', 'bar_class')) {
                $table->string('bar_class')->nullable()->after('consultant_by_label');
            }
            if (! Schema::hasColumn('units', 'print_plan_label')) {
                $table->string('print_plan_label')->default('Plan Of Care')->after('bar_class');
            }
            if (! Schema::hasColumn('units', 'print_narrative_label')) {
                $table->string('print_narrative_label')->default('To be followed')->after('print_plan_label');
            }
        });

        foreach (self::PROFILES as $code => $p) {
            DB::table('units')->where('code', $code)->update($p);
        }
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn([
                'display_order', 'active', 'extra_row_fields', 'bed_label',
                'consultant_pair', 'consultant_by_label', 'bar_class',
                'print_plan_label', 'print_narrative_label',
            ]);
        });
    }
};
