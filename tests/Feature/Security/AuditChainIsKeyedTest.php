<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Support\AuditChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SPC-RPT-004 / SPC-TM-003 / SPC-DATABASE-002 — found independently by three domains.
 *
 * The chain was `sha256(prev_hash . canonical)`. Every input was a column in the table and
 * the algorithm was public, so anyone with UPDATE on audit_log could edit a row, recompute
 * the tail with the same formula audit:verify uses, and be told "chain intact". The trail
 * is the compensating control named against most other findings in this system, so this is
 * the one that has to actually hold.
 */
class AuditChainIsKeyedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stored_hash_cannot_be_reproduced_from_the_table_alone(): void
    {
        $row = AuditLog::record('login', 'member=7', 7, '10.0.0.1');

        $canonical = implode('|', [
            (string) $row->user_id,
            $row->action,
            (string) $row->detail,
            (string) $row->ip,
            $row->created_at->toIso8601String(),
        ]);

        // Exactly what an attacker holding only the database would compute.
        $unkeyed = hash('sha256', ((string) $row->prev_hash).$canonical);

        $this->assertNotSame($unkeyed, $row->hash, 'the chain must not be recomputable without the key');
        $this->assertSame(AuditChain::VERSION, (int) $row->hash_version);
    }

    public function test_a_tampered_row_is_detected(): void
    {
        AuditLog::record('login', 'member=1', 1, '10.0.0.1');
        $target = AuditLog::record('endorsement_signoff_reopen', 'unit=1', 1, '10.0.0.1');
        AuditLog::record('logout', 'member=1', 1, '10.0.0.1');

        // The insider edit this exists to catch: make the reopen look like something benign.
        DB::table('audit_log')->where('id', $target->id)->update(['action' => 'endorsement_view']);

        $this->artisan('audit:verify')->assertExitCode(1);
    }

    public function test_recomputing_the_tail_with_the_public_formula_does_not_repair_it(): void
    {
        AuditLog::record('login', 'member=1', 1, '10.0.0.1');
        $target = AuditLog::record('endorsement_signoff_reopen', 'unit=1', 1, '10.0.0.1');

        DB::table('audit_log')->where('id', $target->id)->update(['action' => 'endorsement_view']);

        // Attacker recomputes the altered row's hash with the algorithm they can read.
        $row = DB::table('audit_log')->where('id', $target->id)->first();
        $canonical = implode('|', [
            (string) $row->user_id,
            (string) $row->action,
            (string) $row->detail,
            (string) $row->ip,
            \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String(),
        ]);
        DB::table('audit_log')->where('id', $target->id)->update([
            'hash' => hash('sha256', ((string) $row->prev_hash).$canonical),
        ]);

        $this->artisan('audit:verify')->assertExitCode(1);
    }

    public function test_rows_written_before_the_keyed_chain_still_verify(): void
    {
        // A security improvement must not retroactively declare correctly-recorded history
        // invalid — an hourly check that cries wolf is one nobody reads.
        $canonical = implode('|', ['1', 'login', 'member=1', '10.0.0.1', now()->toIso8601String()]);

        DB::table('audit_log')->insert([
            'user_id' => 1,
            'action' => 'login',
            'detail' => 'member=1',
            'ip' => '10.0.0.1',
            'prev_hash' => null,
            'hash' => AuditChain::hash(null, $canonical, AuditChain::VERSION_UNKEYED),
            'hash_version' => null,   // pre-change row
            'created_at' => now(),
        ]);

        $this->artisan('audit:verify')->assertExitCode(0);
    }

    public function test_the_derived_key_is_domain_separated_from_the_app_key(): void
    {
        $canonical = 'x|y|z';

        $hash = AuditChain::hash(null, $canonical);

        // Not the raw APP_KEY used as an HMAC secret, and not an unkeyed digest.
        $appKey = (string) config('app.key');
        $this->assertNotSame(hash_hmac('sha256', $canonical, $appKey), $hash);
        $this->assertNotSame(hash('sha256', $canonical), $hash);
    }
}
