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
    /** The historical UnitProfile registry, verbatim — this is the data being moved. */
    private const PROFILES = [
        'PICU' => [1, '[]', 'Bed', true, 'Consultant covering', 'channel-bar-picu', 'Plan Of Care', 'New events'],
        'NICU' => [2, '["dob"]', 'Bed', true, 'Consultant covering', 'channel-bar-nicu', 'Plan Of Care', 'To be followed'],
        'SCBU' => [3, '["dob"]', 'Bed', true, 'Consultant covering', 'channel-bar-scbu', 'Plan Of Care', 'To be followed'],
        'WARD' => [4, '["age","ward_unit"]', 'Room', false, 'Consultant Oncall', 'channel-bar-ward', 'Management', 'To be followed'],
    ];

    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->unsignedSmallInteger('display_order')->default(0)->after('name');
            // Retirement is now explicit. The spec requires retired unit codes to 404;
            // previously that was expressed by absence from a PHP array.
            $table->boolean('active')->default(true)->after('display_order');
            $table->json('extra_row_fields')->nullable()->after('active');
            $table->string('bed_label')->default('Bed')->after('extra_row_fields');
            $table->boolean('consultant_pair')->default(true)->after('bed_label');
            $table->string('consultant_by_label')->default('Consultant covering')->after('consultant_pair');
            $table->string('bar_class')->nullable()->after('consultant_by_label');
            $table->string('print_plan_label')->default('Plan Of Care')->after('bar_class');
            $table->string('print_narrative_label')->default('To be followed')->after('print_plan_label');
        });

        foreach (self::PROFILES as $code => $p) {
            DB::table('units')->where('code', $code)->update([
                'display_order' => $p[0],
                'active' => true,
                'extra_row_fields' => $p[1],
                'bed_label' => $p[2],
                'consultant_pair' => $p[3],
                'consultant_by_label' => $p[4],
                'bar_class' => $p[5],
                'print_plan_label' => $p[6],
                'print_narrative_label' => $p[7],
            ]);
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
