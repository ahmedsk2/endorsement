<?php

namespace Tests\Feature\Calendar;

use App\Models\Institution;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Munawib AR-08 / ST-01: the per-department calendar configuration `App\Support\Calendar`
 * reads, added to `institutions` by 2026_08_12_120001_add_calendar_settings_to_institutions.
 *
 * Deliberately NOT covered here: a `timezone` column. Owner decision 3 (2026-08-08) keeps
 * timezone on the INSTANCE (`APP_TIMEZONE`) and only `hijri_offset_days` on the department —
 * see the migration's docblock.
 */
class InstitutionCalendarSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function freshInstitution(): Institution
    {
        return Institution::create(['code' => 'TEST', 'name' => 'Test Hospital', 'active' => true]);
    }

    public function test_a_freshly_migrated_row_has_the_documented_defaults(): void
    {
        $institution = $this->freshInstitution();

        $this->assertTrue($institution->hijri_enabled);
        $this->assertSame(0, $institution->hijri_offset_days);
        $this->assertSame([5, 6], $institution->weekend_days);
        $this->assertSame(Institution::PERIOD_WEEK_BLOCKS, $institution->period_type);
        $this->assertSame([4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 5], $institution->block_weeks);
        $this->assertNull($institution->academic_year_start);
    }

    public function test_the_casts_return_types_not_strings(): void
    {
        $institution = $this->freshInstitution()->fresh();

        $this->assertIsBool($institution->hijri_enabled);
        $this->assertIsInt($institution->hijri_offset_days);
        $this->assertIsArray($institution->weekend_days);
        $this->assertIsArray($institution->block_weeks);
    }

    public function test_seeding_with_hijri_offset_days_configured_writes_it(): void
    {
        config(['endorsement.hijri_offset_days' => '-1']);

        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseHas('institutions', ['code' => 'QCH', 'hijri_offset_days' => -1]);
    }

    /**
     * A customer's calibration must survive a re-seed — `db:seed --force` runs on every
     * deploy, so an unset HIJRI_OFFSET_DAYS on a later deploy must not silently zero it back
     * out. The same defect ReferenceSeeder.php already fixed once for `name`.
     */
    public function test_reseeding_with_the_variable_unset_leaves_a_calibrated_offset_alone(): void
    {
        config(['endorsement.hijri_offset_days' => '-1']);
        $this->seed(ReferenceSeeder::class);

        config(['endorsement.hijri_offset_days' => null]);
        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseHas('institutions', ['code' => 'QCH', 'hijri_offset_days' => -1]);
    }

    public function test_an_offset_outside_bounds_is_refused_naming_the_variable(): void
    {
        config(['endorsement.hijri_offset_days' => '3']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HIJRI_OFFSET_DAYS');

        $this->seed(ReferenceSeeder::class);
    }

    public function test_a_negative_offset_outside_bounds_is_also_refused(): void
    {
        config(['endorsement.hijri_offset_days' => '-3']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HIJRI_OFFSET_DAYS');

        $this->seed(ReferenceSeeder::class);
    }

    /**
     * A rejected offset must not partially write — the institution's offset stays at
     * whatever it was (0 on first seed) rather than landing on the out-of-bounds value.
     */
    public function test_a_rejected_offset_does_not_get_written(): void
    {
        config(['endorsement.hijri_offset_days' => '3']);

        try {
            $this->seed(ReferenceSeeder::class);
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseHas('institutions', ['code' => 'QCH', 'hijri_offset_days' => 0]);
    }
}
