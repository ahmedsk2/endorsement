<?php

namespace Tests\Feature\Console;

use App\Support\AppSettings;
use App\Support\OpsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The scheduled jobs detected the right failures and then told nobody: Log::critical to
 * stderr with no destination configured. These pin the escalation path.
 */
class OpsAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_always_logs_critical_even_with_no_recipient(): void
    {
        Log::shouldReceive('critical')->once()->withArgs(
            fn (string $line) => str_contains($line, 'Nightly backup FAILED'),
        );
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        OpsAlert::critical('Nightly backup FAILED', 'No recoverable copy was produced.');
    }

    public function test_it_emails_the_configured_address_once_smtp_exists(): void
    {
        Mail::fake();
        AppSettings::set('alert_email', 'oncall@example.org');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.org']);

        OpsAlert::critical('Audit chain verification FAILED', 'The trail may have been altered.');

        Mail::assertSentCount(1);
    }

    public function test_it_stays_silent_when_no_address_is_configured(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.org']);

        OpsAlert::critical('Nightly backup FAILED');

        Mail::assertNothingSent();
    }

    public function test_it_does_not_attempt_delivery_before_smtp_is_configured(): void
    {
        Mail::fake();
        AppSettings::set('alert_email', 'oncall@example.org');
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => null]);

        OpsAlert::critical('Nightly backup FAILED');

        Mail::assertNothingSent();
    }

    public function test_the_smtp_guard_engages_under_the_real_production_defaults(): void
    {
        // The condition that actually shipped, and that the test above never reproduced.
        // The owner has not configured SMTP, so the mailer is still 'log' — but
        // mail.mailers.smtp.host is NOT empty, because config/mail.php defaults it to
        // 127.0.0.1. The guard read that default as "SMTP exists". Every test here set the
        // host by hand, which is precisely the value being tested, so the suite agreed with
        // itself while production quietly posted alerts to a port nothing listens on.
        Mail::fake();
        AppSettings::set('alert_email', 'oncall@example.org');
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => '127.0.0.1']);

        OpsAlert::critical('Nightly backup FAILED');

        Mail::assertNothingSent();
    }

    public function test_a_failing_mailer_never_propagates_out_of_the_alert(): void
    {
        // This runs inside a scheduler onFailure handler. If alerting throws, a recoverable
        // job failure becomes an unhandled exception that takes out the rest of the run.
        AppSettings::set('alert_email', 'oncall@example.org');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.org']);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('relay unreachable'));

        OpsAlert::critical('Nightly backup FAILED');

        $this->addToAssertionCount(1);   // reaching here without an exception is the assertion
    }

    public function test_the_alert_body_carries_no_patient_information(): void
    {
        Mail::fake();
        AppSettings::set('alert_email', 'oncall@example.org');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.org']);

        OpsAlert::critical('Nightly backup FAILED', 'No recoverable copy was produced tonight.');

        Mail::assertSent(\App\Mail\OpsAlertMail::class, function ($mail) {
            $body = (string) $mail->render();

            // A job name and a timestamp — never a row, a name or an MRN.
            $this->assertStringContainsString('Nightly backup FAILED', $body);
            $this->assertStringContainsString('contains no patient information', $body);

            return true;
        });
    }
}
