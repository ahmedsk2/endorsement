<?php

namespace Tests\Feature\Security;

use App\Models\Handover;
use App\Models\Unit;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PHI is ENCRYPTED AT REST (docs/COMPLIANCE.md, layer 4).
 *
 * The threat this closes: a stolen database dump, a backup that leaks, or a compromised
 * database account. Disk/volume encryption protects none of those — the file is readable
 * once mounted, and a DB login sees plaintext. These assertions read the RAW column bytes
 * with the query builder (which bypasses the model casts) and require that no identifier
 * or clinical narrative is legible there.
 */
class PhiEncryptionAtRestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
    }

    private function row(): Handover
    {
        return Handover::create([
            'unit_id' => Unit::where('code', 'NICU')->value('id'),
            'handover_date' => '2026-07-20',
            'bed' => '3',
            'mrn' => 'MRN-778899',
            'patient_name' => 'Fatimah Al-Test',
            'dob' => '2026-07-01 03:20:00',
            'disease' => '<p>Prematurity, <b>RDS</b></p>',
            'details' => 'Mother at bedside',
            'plan' => 'Wean CPAP',
            'nevent' => 'Apnoea x1 overnight',
        ]);
    }

    public function test_identifiers_and_narrative_are_ciphertext_in_the_database(): void
    {
        $row = $this->row();

        // Raw bytes, straight from the table — no model, no casts.
        $raw = DB::table('handovers')->where('id', $row->id)->first();

        foreach ([
            'MRN-778899',
            'Fatimah Al-Test',
            '2026-07-01',
            'Prematurity',
            'Mother at bedside',
            'Wean CPAP',
            'Apnoea',
        ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                json_encode($raw),
                "[{$secret}] is legible in the handovers table — it must be encrypted at rest.",
            );
        }

        // The envelope is Laravel's, not some accidental encoding.
        $this->assertNotSame('MRN-778899', $raw->mrn);
        $this->assertGreaterThan(60, strlen((string) $raw->mrn));
    }

    public function test_the_application_still_reads_everything_back_unchanged(): void
    {
        $row = $this->row();
        $fresh = Handover::findOrFail($row->id);

        $this->assertSame('MRN-778899', $fresh->mrn);
        $this->assertSame('Fatimah Al-Test', $fresh->patient_name);
        $this->assertSame('2026-07-01 03:20', $fresh->dob->format('Y-m-d H:i'));
        $this->assertStringContainsString('Prematurity', (string) $fresh->disease);
        $this->assertStringContainsString('<b>RDS</b>', (string) $fresh->disease);
    }

    /** Sanitisation still happens BEFORE encryption — what is stored is already safe. */
    public function test_rich_text_is_sanitized_before_it_is_encrypted(): void
    {
        $row = Handover::create([
            'unit_id' => Unit::where('code', 'PICU')->value('id'),
            'handover_date' => '2026-07-20',
            'details' => '<b>stable</b><script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsString('<script', (string) $row->fresh()->details);
        $this->assertStringContainsString('<b>stable</b>', (string) $row->fresh()->details);
    }

    /** Non-identifying operational columns stay in the clear — bed sorting must keep working. */
    public function test_bed_and_dates_remain_queryable(): void
    {
        $this->row();

        $raw = DB::table('handovers')->first();
        $this->assertSame('3', $raw->bed);
        $this->assertStringContainsString('2026-07-20', (string) $raw->handover_date);

        // And the unit/date index still resolves rows in SQL.
        $this->assertSame(1, Handover::whereDate('handover_date', '2026-07-20')->count());
    }

    /** P0b bounded custom fields (design §6.2) — the whole-column map is encrypted too. */
    public function test_extra_fields_are_ciphertext_in_the_database(): void
    {
        $row = Handover::create([
            'unit_id' => Unit::where('code', 'NICU')->value('id'),
            'handover_date' => '2026-07-20',
            'bed' => '4',
            'extra_fields' => ['weight' => '3.2', 'allergy' => 'penicillin'],
        ]);

        $raw = DB::table('handovers')->where('id', $row->id)->first();

        foreach (['weight', '3.2', 'allergy', 'penicillin'] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                json_encode($raw),
                "[{$secret}] is legible in handovers.extra_fields — it must be encrypted at rest.",
            );
        }
    }

    public function test_extra_fields_round_trip_through_the_model(): void
    {
        $row = Handover::create([
            'unit_id' => Unit::where('code', 'NICU')->value('id'),
            'handover_date' => '2026-07-20',
            'bed' => '4',
            'extra_fields' => ['weight' => '3.2', 'allergy' => 'penicillin'],
        ]);

        $fresh = Handover::findOrFail($row->id);

        $this->assertSame(['allergy' => 'penicillin', 'weight' => '3.2'], $fresh->extra_fields);
    }

    /** A row that has never had a custom field set reads back as an empty array, never null. */
    public function test_extra_fields_defaults_to_an_empty_array(): void
    {
        $row = $this->row();

        $this->assertSame([], $row->fresh()->extra_fields);
    }

    /**
     * A row written BEFORE encryption (or by a direct SQL insert) must still be readable —
     * the cast falls back to plaintext rather than losing a clinician's handover.
     */
    public function test_a_plaintext_legacy_row_is_still_readable(): void
    {
        $id = DB::table('handovers')->insertGetId([
            'unit_id' => Unit::where('code', 'PICU')->value('id'),
            'handover_date' => '2026-07-19',
            'mrn' => 'PLAIN-1',
            'patient_name' => 'Older Row',
            'details' => 'written before encryption',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = Handover::findOrFail($id);
        $this->assertSame('PLAIN-1', $row->mrn);
        $this->assertSame('written before encryption', $row->details);
    }
}
