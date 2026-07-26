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
        // A fixed UTC instant, not now(): a legacy row was written by an application running
        // on UTC, and stating that explicitly is the whole point of the fixture. Deriving
        // the stored value and the canonical string from two separate now() calls also made
        // this test depend on the machine's timezone and on not straddling a second.
        $writtenAt = '2026-07-25 13:30:51';

        // The v1 timestamp rendering, written out literally rather than by calling the code
        // under test to produce its own expected value.
        $canonical = implode('|', ['1', 'login', 'member=1', '10.0.0.1', '2026-07-25T13:30:51+00:00']);

        DB::table('audit_log')->insert([
            'user_id' => 1,
            'action' => 'login',
            'detail' => 'member=1',
            'ip' => '10.0.0.1',
            'prev_hash' => null,
            'hash' => AuditChain::hash(null, $canonical, AuditChain::VERSION_UNKEYED),
            'hash_version' => null,   // pre-change row
            'created_at' => $writtenAt,
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

    public function test_the_chain_survives_a_change_of_application_timezone(): void
    {
        // The trap that made fixing APP_TIMEZONE risky, and that this test's first version
        // FAILED to catch: it only called config(['app.timezone' => ...]), which does not
        // touch PHP's default timezone, so neither now() nor Carbon::parse() actually moved
        // and the test proved nothing. Production moved for real and audit:verify declared
        // the whole trail broken. Setting the process timezone is what makes this faithful.
        //
        // On a control whose whole job is tamper-evidence, a false positive is as damaging
        // as a false negative: it teaches its only reader to ignore it.
        $this->withApplicationTimezone('UTC', function () {
            AuditLog::record('backup_created', 'files=3');
            AuditLog::record('login', 'member=1', 1, '10.0.0.1');
        });

        // The deploy that set APP_TIMEZONE=Asia/Riyadh. Nothing in the table changed.
        $this->withApplicationTimezone('Asia/Riyadh', function () {
            $this->artisan('audit:verify')->assertExitCode(0);
        });
    }

    public function test_the_chain_still_verifies_after_a_second_timezone_change(): void
    {
        // Rows written on Riyadh must not become unverifiable if the timezone is ever
        // corrected again — otherwise this fix is a trap laid for the next person.
        $this->withApplicationTimezone('Asia/Riyadh', function () {
            AuditLog::record('backup_created', 'files=3');
        });

        $this->withApplicationTimezone('Europe/London', function () {
            $this->artisan('audit:verify')->assertExitCode(0);
        });
    }

    /**
     * Run $body with the application genuinely on $timezone — config AND the process
     * default, because Laravel's date helpers read the latter.
     */
    private function withApplicationTimezone(string $timezone, callable $body): void
    {
        $previousConfig = config('app.timezone');
        $previousDefault = date_default_timezone_get();

        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);

        try {
            $body();
        } finally {
            config(['app.timezone' => $previousConfig]);
            date_default_timezone_set($previousDefault);
        }
    }
}
