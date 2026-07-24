<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The audit log is hash-chained on write (AuditLog::record), but a chain nobody can verify
 * is tamper-evidence in name only. `audit:verify` walks the chain head-to-tail, recomputes
 * every hash, and exits non-zero naming the first broken row.
 */
class AuditVerifyCommandTest extends TestCase
{
    use RefreshDatabase;

    private function writeChain(int $rows): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < $rows; $i++) {
            AuditLog::record('test_action_'.$i, 'seq='.$i, $user->id, '127.0.0.1');
        }
    }

    public function test_an_intact_chain_verifies_clean(): void
    {
        $this->writeChain(5);

        $this->artisan('audit:verify')
            ->expectsOutputToContain('5 rows verified')
            ->assertExitCode(0);
    }

    public function test_an_empty_log_verifies_clean(): void
    {
        $this->artisan('audit:verify')->assertExitCode(0);
    }

    public function test_a_tampered_detail_breaks_the_chain_at_that_row(): void
    {
        $this->writeChain(5);

        $victim = AuditLog::query()->orderBy('id')->skip(2)->first();
        // Simulate direct DB tampering (bypassing the model, as an attacker would).
        DB::table('audit_log')->where('id', $victim->id)->update(['detail' => 'seq=FORGED']);

        $this->artisan('audit:verify')
            ->expectsOutputToContain('row '.$victim->id)
            ->assertExitCode(1);
    }

    public function test_a_deleted_row_breaks_the_chain(): void
    {
        $this->writeChain(5);

        $victim = AuditLog::query()->orderBy('id')->skip(2)->first();
        DB::table('audit_log')->where('id', $victim->id)->delete();

        $this->artisan('audit:verify')->assertExitCode(1);
    }
}
