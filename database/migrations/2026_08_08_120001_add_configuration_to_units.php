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
        // If a prior run added the columns but died before the `migrations` row was written,
        // a re-run must not try to add them again ("Duplicate column name") — it would leave
        // the owner hand-repairing a clinical database instead of just re-running artisan.
        if (! Schema::hasColumn('units', 'display_order')) {
            Schema::table('units', function (Blueprint $table) {
                // Default HIGH, not 0: seeded units occupy 1-4, and any unit created outside
                // the seeder — which is the entire point of this change — must sort AFTER
                // them until an administrator gives it a real position, not ahead of PICU.
                $table->unsignedSmallInteger('display_order')->default(1000)->after('name');
                // Retirement is now explicit. The spec requires retired unit codes to 404;
                // previously that was expressed by absence from a PHP array.
                $table->boolean('active')->default(true)->after('display_order');
                $table->json('extra_row_fields')->nullable()->after('active');
                $table->string('bed_label')->default('Bed')->after('extra_row_fields');
                $table->boolean('consultant_pair')->default(true)->after('bed_label');
                $table->string('consultant_by_label')->default('Consultant covering')->after('consultant_pair');
                // Nullable with no default is intentional: App\Support\UnitProfile derives
                // 'channel-bar-'.strtolower($code) as a fallback when this is NULL. Adding a
                // default here would defeat that fallback for any unit the backfill missed.
                $table->string('bar_class')->nullable()->after('consultant_by_label');
                $table->string('print_plan_label')->default('Plan Of Care')->after('bar_class');
                $table->string('print_narrative_label')->default('To be followed')->after('print_plan_label');
            });
        }

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
