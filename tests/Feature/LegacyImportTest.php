<?php

namespace Tests\Feature;

use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Hermetic coverage for `legacy:import` — ALL FOUR legacy table pairs (spec §12), lossless
 * via (legacy_source_table, legacy_id) provenance (ruling 7). The `legacy` connection is
 * re-pointed at an isolated in-memory sqlite DB shaped like the legacy schema, so the same
 * connection-agnostic code path runs here and against production MySQL. The fixture bakes
 * in every documented landmine: duplicate (date,mrn,bed) rows, 0000-00-00 and 1970-01-01
 * dates, zero-date dobs, junk endorser ids, blank template rows, unsanitised HTML, and
 * WARD's consultantoncall drift.
 */
class LegacyImportTest extends TestCase
{
    use RefreshDatabase;

    private string $reconPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);

        $this->reconPath = sys_get_temp_dir().'/endorse_recon_'.uniqid().'.md';
        config(['legacy.reconciliation_path' => $this->reconPath]);

        $this->bootLegacyDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->reconPath) && is_file($this->reconPath)) {
            @unlink($this->reconPath);
        }

        DB::purge('legacy');

        parent::tearDown();
    }

    private function bootLegacyDatabase(): void
    {
        config(['database.connections.legacy' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        DB::purge('legacy');

        $schema = Schema::connection('legacy');

        // PICU workhorse table (no dob / age / unit).
        $schema->create('patintsendorcement', function ($table) {
            $table->integer('ID');
            $table->text('MRN')->nullable();
            $table->text('PNAME')->nullable();
            $table->text('DISEASE')->nullable();
            $table->text('DETAILS')->nullable();
            $table->text('BED')->nullable();
            $table->text('STAYDATE')->nullable();
            $table->text('PLAN')->nullable();
            $table->text('nevent')->nullable();
        });
        foreach (['nicu_patintsendorcement', 'scbu_patintsendorcement'] as $t) {
            $schema->create($t, function ($table) {
                $table->integer('ID');
                $table->text('MRN')->nullable();
                $table->text('PNAME')->nullable();
                $table->text('DISEASE')->nullable();
                $table->text('DETAILS')->nullable();
                $table->text('BED')->nullable();
                $table->text('STAYDATE')->nullable();
                $table->text('PLAN')->nullable();
                $table->text('nevent')->nullable();
                $table->text('dob')->nullable();
            });
        }
        $schema->create('ward_patintsendorcement', function ($table) {
            $table->integer('ID');
            $table->text('MRN')->nullable();
            $table->text('PNAME')->nullable();
            $table->text('DISEASE')->nullable();
            $table->text('DETAILS')->nullable();
            $table->text('BED')->nullable();
            $table->text('STAYDATE')->nullable();
            $table->text('PLAN')->nullable();
            $table->text('nevent')->nullable();
            $table->text('unit')->nullable();
            $table->text('age')->nullable();
        });

        // Day-header (attestation) tables. WARD drifts: single consultantoncall.
        foreach (['endorsement', 'nicu_endorsement', 'scbu_endorsement'] as $t) {
            $schema->create($t, function ($table) {
                $table->integer('ID');
                $table->text('Dates')->nullable();
                $table->string('endorsedby')->nullable();
                $table->string('endorsedto')->nullable();
                $table->text('consultantby')->nullable();
                $table->text('consultantto')->nullable();
                $table->text('time')->nullable();
            });
        }
        $schema->create('ward_endorsement', function ($table) {
            $table->integer('ID');
            $table->text('Dates')->nullable();
            $table->string('endorsedby')->nullable();
            $table->string('endorsedto')->nullable();
            $table->text('consultantoncall')->nullable();
            $table->text('time')->nullable();
        });

        $schema->create('members', function ($table) {
            $table->string('member_id');
            $table->string('member_name');
            $table->text('full_name')->nullable();
            $table->string('member_email')->nullable();
            $table->string('member_password');
            $table->integer('position')->nullable();
            $table->integer('active')->default(1);
            $table->text('pass_exp_date')->nullable();
        });
    }

    private function legacy(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection('legacy');
    }

    private function seedTypicalFixture(): void
    {
        $this->legacy()->table('members')->insert([
            ['member_id' => '7', 'member_name' => 'dr_resident', 'full_name' => 'Dr Resident', 'member_email' => null, 'member_password' => '$2y$10$legacyhashlegacyhashlegacyhashle', 'position' => 4, 'active' => 1, 'pass_exp_date' => null],
        ]);

        $this->legacy()->table('patintsendorcement')->insert([
            ['ID' => 1, 'MRN' => '111', 'PNAME' => 'Picu Child', 'DISEASE' => '<p>Sepsis</p>', 'DETAILS' => '<b>stable</b><script>alert(1)</script>', 'BED' => '2', 'STAYDATE' => '2020-01-01', 'PLAN' => 'abx', 'nevent' => 'follow gas'],
            // Duplicate natural key (same date/mrn/bed) — MUST import as its own row (lossless).
            ['ID' => 2, 'MRN' => '111', 'PNAME' => 'Picu Child', 'DISEASE' => 'dup', 'DETAILS' => null, 'BED' => '2', 'STAYDATE' => '2020-01-01', 'PLAN' => null, 'nevent' => null],
            // Blank template row.
            ['ID' => 3, 'MRN' => null, 'PNAME' => null, 'DISEASE' => null, 'DETAILS' => null, 'BED' => null, 'STAYDATE' => '2020-01-02', 'PLAN' => null, 'nevent' => null],
            // Epoch garbage date -> null, still imported.
            ['ID' => 4, 'MRN' => '112', 'PNAME' => 'Epoch Child', 'DISEASE' => null, 'DETAILS' => null, 'BED' => '3', 'STAYDATE' => '1970-01-01', 'PLAN' => null, 'nevent' => null],
        ]);

        $this->legacy()->table('nicu_patintsendorcement')->insert([
            ['ID' => 1, 'MRN' => '221', 'PNAME' => 'Nicu Child', 'DISEASE' => 'RDS', 'DETAILS' => 'on CPAP', 'BED' => '5', 'STAYDATE' => '2020-02-01', 'PLAN' => 'wean', 'nevent' => 'apnoea x1', 'dob' => '2020-01-20 04:30:00'],
            // Zero-date dob -> null.
            ['ID' => 2, 'MRN' => '222', 'PNAME' => 'Zero Dob', 'DISEASE' => null, 'DETAILS' => null, 'BED' => '6', 'STAYDATE' => '2020-02-01', 'PLAN' => null, 'nevent' => null, 'dob' => '0000-00-00 00:00:00'],
        ]);

        $this->legacy()->table('scbu_patintsendorcement')->insert([
            ['ID' => 1, 'MRN' => '331', 'PNAME' => 'Scbu Child', 'DISEASE' => 'jaundice', 'DETAILS' => 'photo', 'BED' => '1', 'STAYDATE' => '2020-03-01', 'PLAN' => 'bili', 'nevent' => null, 'dob' => '2020-02-25 12:00:00'],
        ]);

        $this->legacy()->table('ward_patintsendorcement')->insert([
            ['ID' => 1, 'MRN' => '441', 'PNAME' => 'Ward Child', 'DISEASE' => 'pneumonia', 'DETAILS' => 'improving', 'BED' => '12', 'STAYDATE' => '2020-04-01', 'PLAN' => 'switch po', 'nevent' => 'fever spike', 'unit' => 'Hematology', 'age' => '4 years'],
        ]);

        $this->legacy()->table('endorsement')->insert([
            ['ID' => 1, 'Dates' => '2020-01-01', 'endorsedby' => '7', 'endorsedto' => 'asdaaa', 'consultantby' => 'Dr Free Text', 'consultantto' => '', 'time' => '7:30 Am'],
            // Zero-date header — skipped (nothing to attach it to), counted as expected divergence.
            ['ID' => 2, 'Dates' => '0000-00-00', 'endorsedby' => null, 'endorsedto' => null, 'consultantby' => null, 'consultantto' => null, 'time' => null],
        ]);

        $this->legacy()->table('ward_endorsement')->insert([
            ['ID' => 1, 'Dates' => '2020-04-01', 'endorsedby' => '7', 'endorsedto' => null, 'consultantoncall' => 'Dr Oncall Ward', 'time' => '13:30'],
        ]);
    }

    private function unitId(string $code): int
    {
        return (int) Unit::where('code', $code)->value('id');
    }

    public function test_all_four_units_import_losslessly_with_provenance(): void
    {
        $this->seedTypicalFixture();

        $this->artisan('legacy:import')->assertExitCode(0);

        // Every legacy row lands, including the natural-key duplicate and the blank template.
        $this->assertSame(4, Handover::where('unit_id', $this->unitId('PICU'))->count());
        $this->assertSame(2, Handover::where('unit_id', $this->unitId('NICU'))->count());
        $this->assertSame(1, Handover::where('unit_id', $this->unitId('SCBU'))->count());
        $this->assertSame(1, Handover::where('unit_id', $this->unitId('WARD'))->count());

        // Provenance is stamped, so the duplicate pair are distinct rows.
        $dupes = Handover::where('legacy_source_table', 'patintsendorcement')
            ->whereIn('legacy_id', [1, 2])->get();
        $this->assertCount(2, $dupes);

        // Per-unit columns crossed: NICU dob, WARD age + unit -> ward_unit.
        $nicu = Handover::where('legacy_source_table', 'nicu_patintsendorcement')->where('legacy_id', 1)->firstOrFail();
        $this->assertSame('2020-01-20 04:30', $nicu->dob->format('Y-m-d H:i'));
        $this->assertStringContainsString('apnoea x1', (string) $nicu->nevent);

        $ward = Handover::where('legacy_source_table', 'ward_patintsendorcement')->where('legacy_id', 1)->firstOrFail();
        $this->assertSame('Hematology', $ward->ward_unit);
        $this->assertSame('4 years', $ward->age);
    }

    public function test_data_landmines_are_cleaned_not_dropped(): void
    {
        $this->seedTypicalFixture();
        $this->artisan('legacy:import')->assertExitCode(0);

        // 1970-01-01 STAYDATE -> null date, row kept.
        $epoch = Handover::where('legacy_source_table', 'patintsendorcement')->where('legacy_id', 4)->firstOrFail();
        $this->assertNull($epoch->handover_date);

        // Zero-date dob -> null.
        $zeroDob = Handover::where('legacy_source_table', 'nicu_patintsendorcement')->where('legacy_id', 2)->firstOrFail();
        $this->assertNull($zeroDob->dob);

        // Blank template row kept, cleaned to nulls.
        $blank = Handover::where('legacy_source_table', 'patintsendorcement')->where('legacy_id', 3)->firstOrFail();
        $this->assertNull($blank->mrn);
    }

    public function test_rich_text_is_sanitized_on_import(): void
    {
        $this->seedTypicalFixture();
        $this->artisan('legacy:import')->assertExitCode(0);

        $row = Handover::where('legacy_source_table', 'patintsendorcement')->where('legacy_id', 1)->firstOrFail();

        $this->assertStringNotContainsString('<script', (string) $row->details);
        $this->assertStringContainsString('<b>stable</b>', (string) $row->details);
    }

    public function test_signoffs_import_signed_with_resolution_and_ward_oncall_mapping(): void
    {
        $this->seedTypicalFixture();
        $this->artisan('legacy:import')->assertExitCode(0);

        $picu = HandoverSignoff::where('unit_id', $this->unitId('PICU'))
            ->whereDate('handover_date', '2020-01-01')->firstOrFail();

        // Resolvable member id -> user FK + name snapshot; junk id -> null FK, no name.
        $user = User::where('member_name', 'dr_resident')->firstOrFail();
        $this->assertSame($user->id, $picu->endorsed_by_user_id);
        $this->assertSame('Dr Resident', $picu->endorsed_by_name);
        $this->assertNull($picu->endorsed_to_user_id);

        // Free-text consultant crosses as a snapshot; verbatim legacy time label kept.
        $this->assertSame('Dr Free Text', $picu->consultant_by_name);
        $this->assertSame('7:30 Am', $picu->endorsement_time);
        $this->assertSame(450, $picu->endorsement_time_minutes);

        // Historically final -> signed (locked), null signer marks it as imported.
        $this->assertNotNull($picu->signed_off_at);
        $this->assertNull($picu->signed_off_by_user_id);

        // Ruling 5 — WARD's consultantoncall lands in consultant_by_name.
        $ward = HandoverSignoff::where('unit_id', $this->unitId('WARD'))
            ->whereDate('handover_date', '2020-04-01')->firstOrFail();
        $this->assertSame('Dr Oncall Ward', $ward->consultant_by_name);
        $this->assertNull($ward->consultant_to_name);

        // The zero-date header was skipped: exactly one PICU sign-off exists.
        $this->assertSame(1, HandoverSignoff::where('unit_id', $this->unitId('PICU'))->count());
    }

    public function test_users_import_with_verbatim_hashes(): void
    {
        $this->seedTypicalFixture();
        $this->artisan('legacy:import --only=users')->assertExitCode(0);

        $user = User::where('member_name', 'dr_resident')->firstOrFail();
        $this->assertSame('$2y$10$legacyhashlegacyhashlegacyhashle', $user->getAuthPassword());
        $this->assertSame(4, $user->position);
    }

    public function test_the_import_is_idempotent(): void
    {
        $this->seedTypicalFixture();

        $this->artisan('legacy:import')->assertExitCode(0);
        $this->artisan('legacy:import')->assertExitCode(0);

        $this->assertSame(8, Handover::count());
        // One valid PICU header (the zero-date one is skipped) + one WARD header.
        $this->assertSame(2, HandoverSignoff::count());
        $this->assertSame(1, User::where('member_name', 'dr_resident')->count());
    }

    public function test_rows_born_in_this_app_are_never_touched(): void
    {
        $this->seedTypicalFixture();

        $native = Handover::create([
            'unit_id' => $this->unitId('PICU'),
            'handover_date' => '2020-01-01',
            'mrn' => '111',
            'bed' => '2',
            'plan' => 'native row',
        ]);

        $this->artisan('legacy:import')->assertExitCode(0);

        // The natural key collides with legacy row 1, but provenance keying leaves it alone.
        $this->assertStringContainsString('native row', (string) $native->fresh()->plan);
        $this->assertSame(5, Handover::where('unit_id', $this->unitId('PICU'))->count());
    }

    public function test_the_reconciliation_doc_is_written_counts_only(): void
    {
        $this->seedTypicalFixture();
        $this->artisan('legacy:import')->assertExitCode(0);

        $doc = (string) file_get_contents($this->reconPath);

        $this->assertStringContainsString('handovers:PICU', $doc);
        $this->assertStringContainsString('handovers:WARD', $doc);
        // Counts only — never patient data or hashes.
        $this->assertStringNotContainsString('Picu Child', $doc);
        $this->assertStringNotContainsString('legacyhash', $doc);
    }

    public function test_legacy_reconcile_gates_on_drift(): void
    {
        $this->seedTypicalFixture();
        $this->artisan('legacy:import')->assertExitCode(0);

        // Clean import -> the gate passes.
        $this->artisan('legacy:reconcile')->assertExitCode(0);

        // Simulate drift: an imported row vanishes (hard delete bypassing soft deletes).
        DB::table('handovers')->where('legacy_source_table', 'ward_patintsendorcement')->delete();

        $this->artisan('legacy:reconcile')->assertExitCode(1);
    }
}
