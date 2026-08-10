<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SPC-TM-005. The application recorded exactly the right events and nothing consumed any
 * of them. audit:verify proves the chain is INTACT, which is a different question from
 * whether anything alarming happened inside it.
 */
class AuditAnomaliesTest extends TestCase
{
    use RefreshDatabase;

    private function alertable(): void
    {
        \App\Support\AppSettings::set('alert_email', 'oncall@example.org');
        // Both halves: credentials AND a selected transport. Setting only the host used to
        // be enough here because OpsAlert's guard read that same key — which is exactly why
        // the suite never noticed that production had never selected the smtp mailer.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.org']);
    }

    public function test_a_quiet_window_reports_nothing(): void
    {
        Mail::fake();
        $this->alertable();

        $this->artisan('audit:anomalies')->assertExitCode(0);

        // A sweep that alerts on an ordinary shift teaches its reader to ignore it.
        Mail::assertNothingSent();
    }

    public function test_a_reversed_attestation_is_always_reported(): void
    {
        Mail::fake();
        $this->alertable();

        AuditLog::record('endorsement_signoff_reopen', 'unit=1 date=2026-07-26', 3, '10.0.0.1');

        $this->artisan('audit:anomalies')->assertExitCode(0);

        Mail::assertSent(\App\Mail\OpsAlertMail::class);
    }

    public function test_bulk_printing_by_one_user_is_reported(): void
    {
        Mail::fake();
        $this->alertable();

        // The departing-resident scenario: walk the archive and print it.
        for ($i = 0; $i < 30; $i++) {
            AuditLog::record('endorsement_print', 'unit=1 rows=12', 9, '10.0.0.1');
        }

        $this->artisan('audit:anomalies')->assertExitCode(0);

        Mail::assertSent(\App\Mail\OpsAlertMail::class, function ($mail) {
            $body = (string) $mail->render();

            $this->assertStringContainsString('user=9', $body);
            $this->assertStringContainsString('endorsement_print', $body);

            // Ids and counts only — never a patient identifier.
            $this->assertStringContainsString('contains no patient information', $body);

            return true;
        });
    }

    public function test_ordinary_clinical_reading_does_not_trip_the_bulk_threshold(): void
    {
        Mail::fake();
        $this->alertable();

        // A busy ward round across a full census, several times over.
        for ($i = 0; $i < 40; $i++) {
            AuditLog::record('endorsement_view', 'unit=1 rows=15', 4, '10.0.0.1');
        }

        $this->artisan('audit:anomalies')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_repeated_refusals_are_reported(): void
    {
        Mail::fake();
        $this->alertable();

        for ($i = 0; $i < 12; $i++) {
            AuditLog::record('access_denied', 'cap=users.manage', 5, '10.0.0.1');
        }

        $this->artisan('audit:anomalies')->assertExitCode(0);

        Mail::assertSent(\App\Mail\OpsAlertMail::class);
    }

    public function test_a_run_of_failed_second_factors_is_reported(): void
    {
        // The sweep's own comment claimed it covered "a failing factor" and it did not.
        // A password that works plus a second factor that keeps failing is the signature of
        // a stolen credential meeting the control that stopped it — precisely the thing a
        // breach clock should start on.
        Mail::fake();
        $this->alertable();

        for ($i = 0; $i < 12; $i++) {
            AuditLog::record('2fa_failed', 'member=5', 5, '10.0.0.1');
        }

        $this->artisan('audit:anomalies')->assertExitCode(0);

        Mail::assertSent(\App\Mail\OpsAlertMail::class);
    }

    public function test_failed_email_codes_are_reported_even_though_nobody_is_signed_in(): void
    {
        // This one records NO user_id — it happens before a session exists — so per-user
        // counting silently skips it. Counted by source address instead, which is only
        // meaningful now that the address is the client's rather than a Cloudflare edge.
        Mail::fake();
        $this->alertable();

        for ($i = 0; $i < 12; $i++) {
            AuditLog::record('two_factor_email_failed', 'user=5', null, '203.0.113.5');
        }

        $this->artisan('audit:anomalies')->assertExitCode(0);

        Mail::assertSent(\App\Mail\OpsAlertMail::class);
    }

    /**
     * P1d-2 Decision F. A bulk rota fill rewrites hundreds of cells behind ONE confirmation, so the
     * one row it writes always deserves a human look. It is the FIRST rota action on this list.
     */
    public function test_a_rota_fill_is_reported_as_a_single_occurrence(): void
    {
        Mail::fake();
        $this->alertable();

        AuditLog::record(
            'rota_fill',
            'op=fill_down_column;source_person=7;source_period=3;target_period=none;'
            .'targets=40;assigned=39;replaced=0;unchanged=0;skipped=1',
            3,
            '10.0.0.1',
        );

        $this->artisan('audit:anomalies')->assertExitCode(0);

        Mail::assertSent(\App\Mail\OpsAlertMail::class, function ($mail) {
            $body = (string) $mail->render();

            $this->assertStringContainsString('rota_fill', $body);
            $this->assertStringContainsString('1 x rota_fill', $body);

            return true;
        });
    }

    /**
     * Decision H's reasoning, asserted rather than commented: per-cell rota editing is ordinary
     * work. Fifty of them in an afternoon is a scheduler doing their job, and a watch list that
     * paged fifty times for it is one nobody reads on the fifty-first.
     */
    public function test_per_cell_rota_editing_is_not_watched(): void
    {
        Mail::fake();
        $this->alertable();

        for ($i = 0; $i < 50; $i++) {
            AuditLog::record('rota_assign', "person={$i};period=3;unit=1", 3, '10.0.0.1');
        }

        foreach (['rota_split', 'rota_clear', 'vacation_book', 'vacation_cancel'] as $action) {
            AuditLog::record($action, 'person=1;period=3', 3, '10.0.0.1');
        }

        $this->artisan('audit:anomalies')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_events_outside_the_window_are_ignored(): void
    {
        Mail::fake();
        $this->alertable();

        $row = AuditLog::record('endorsement_signoff_reopen', 'unit=1', 3, '10.0.0.1');
        $row->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $this->artisan('audit:anomalies')->assertExitCode(0);

        Mail::assertNothingSent();
    }
}
