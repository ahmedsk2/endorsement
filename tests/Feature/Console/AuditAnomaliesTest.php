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
