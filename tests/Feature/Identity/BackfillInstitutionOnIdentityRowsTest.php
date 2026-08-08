<?php

namespace Tests\Feature\Identity;

use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reviewer's Minor (2026-08-08): `2026_08_11_120001_backfill_institution_on_identity_rows` had
 * no test covering the one-institution or two-institution branches — only the zero-institution
 * one, implicitly, since `RefreshDatabase` runs every migration (this one included) against an
 * empty `institutions` table before any test seeds one. Eight lines, provably right by reading,
 * but the owner runs this against a live database with real accounts on it — it earns a direct
 * test of all three branches, plus the re-run the migration's own `down()` relies on being safe.
 *
 * `RefreshDatabase` has already run this migration once (against zero institutions) by the time
 * `setUp()` returns. These tests seed institutions and NULL rows afterwards, then invoke `up()`
 * again directly — the exact sequence a real upgrade goes through: an instance that has already
 * been seeded runs the migration once, on real data.
 */
class BackfillInstitutionOnIdentityRowsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_11_120001_backfill_institution_on_identity_rows.php');

        return $migration;
    }

    private function institution(string $code): Institution
    {
        return Institution::create(['code' => $code, 'name' => 'Hospital '.$code, 'active' => true]);
    }

    public function test_zero_institutions_leaves_existing_null_rows_untouched(): void
    {
        $person = Person::factory()->create(['institution_id' => null]);
        $user = User::factory()->create(['institution_id' => null]);

        $this->migration()->up();

        $this->assertNull($person->fresh()->institution_id);
        $this->assertNull($user->fresh()->institution_id);
    }

    public function test_exactly_one_active_institution_backfills_every_null_row(): void
    {
        $institution = $this->institution('QCH');
        $person = Person::factory()->create(['institution_id' => null]);
        $user = User::factory()->create(['institution_id' => null]);

        $this->migration()->up();

        $this->assertSame($institution->id, $person->fresh()->institution_id);
        $this->assertSame($institution->id, $user->fresh()->institution_id);
    }

    /**
     * A row that already carries an institution_id (LegacyImport-stamped, or written after an
     * earlier partial backfill) must not be disturbed — the migration only fills NULLs.
     */
    public function test_it_never_overwrites_a_row_that_already_carries_an_institution_id(): void
    {
        $original = $this->institution('QCH');
        $other = $this->institution('RGH');
        // Two active institutions exist now, but this row was already stamped before that —
        // the migration must leave it exactly as it found it either way.
        $person = Person::factory()->create(['institution_id' => $original->id]);
        $user = User::factory()->create(['institution_id' => $original->id]);

        $this->migration()->up();

        $this->assertSame($original->id, $person->fresh()->institution_id);
        $this->assertSame($original->id, $user->fresh()->institution_id);
        $this->assertNotSame($other->id, $person->fresh()->institution_id);
    }

    public function test_two_active_institutions_makes_no_change(): void
    {
        $this->institution('QCH');
        $this->institution('RGH');
        $person = Person::factory()->create(['institution_id' => null]);
        $user = User::factory()->create(['institution_id' => null]);

        $this->migration()->up();

        $this->assertNull($person->fresh()->institution_id);
        $this->assertNull($user->fresh()->institution_id);
    }

    /**
     * `down()` restores nothing and relies on `up()` being an idempotent no-op the second time
     * it runs against already-backfilled rows — exactly what a re-applied deploy would do.
     */
    public function test_running_it_twice_is_a_no_op_the_second_time(): void
    {
        $institution = $this->institution('QCH');
        $person = Person::factory()->create(['institution_id' => null]);
        $user = User::factory()->create(['institution_id' => null]);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($institution->id, $person->fresh()->institution_id);
        $this->assertSame($institution->id, $user->fresh()->institution_id);
    }
}
