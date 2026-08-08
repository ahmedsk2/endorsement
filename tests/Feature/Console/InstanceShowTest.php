<?php

namespace Tests\Feature\Console;

use App\Models\Institution;
use App\Support\AppSettings;
use App\Support\Instance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * P0d Task 8. The bootstrap sequence (migrate, seed, least-privilege grants, create-admin,
 * SMTP, alert email, VAPID) is three manual, unordered, unenforced steps that nothing checks:
 * a deploy that stops after `migrate` gives a healthy container, a passing `/up`, and 403s on
 * every capability-gated route; a deploy that stops after `db:seed` has no way in at all.
 * `instance:show` turns "did I finish provisioning?" from a checklist line into a command
 * with an answer — and it must never leak a secret VALUE while doing it.
 */
class InstanceShowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('BACKUP_PASSPHRASE=');
        parent::tearDown();
    }

    private function fullyConfigure(): void
    {
        putenv('BACKUP_PASSPHRASE=a-recognisable-test-passphrase-999');
        Institution::create(['code' => 'QCH', 'name' => 'Qatif Central Hospital', 'active' => true]);
        AppSettings::set('mail_host', 'smtp.example.org');
        AppSettings::set('mail_password', 'RecognisableMailPassword123');
        AppSettings::set('alert_email', 'ops@example.org');
        AppSettings::set('vapid_public_key', 'a-test-vapid-public-key');
        AppSettings::set('vapid_private_key', 'a-test-vapid-private-key');
    }

    public function test_it_prints_identity_and_configuration_state(): void
    {
        config(['endorsement.instance.slug' => 'qch']);
        $this->fullyConfigure();

        $exitCode = Artisan::call('instance:show');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('qch', $output);
        $this->assertStringContainsString('QCH', $output);
        $this->assertStringContainsString('Qatif Central Hospital', $output);
        $this->assertStringContainsString((string) config('app.timezone'), $output);
        $this->assertStringContainsString(Instance::keyFingerprint(), $output);

        foreach (['BACKUP_PASSPHRASE', 'mail_host', 'alert_email', 'vapid_public_key', 'vapid_private_key'] as $label) {
            $this->assertStringContainsString($label, $output);
        }

        $this->assertStringContainsString('set', $output);
    }

    /**
     * Set a RECOGNISABLE secret and a recognisable stored password, run the command, and
     * assert neither string appears anywhere in the output — this is the whole point of the
     * command existing, and it must be pinned by a test rather than by eye.
     */
    public function test_no_secret_value_ever_appears_in_the_output(): void
    {
        config(['endorsement.instance.slug' => 'qch']);
        $this->fullyConfigure();

        Artisan::call('instance:show');
        $output = Artisan::output();

        $this->assertStringNotContainsString('a-recognisable-test-passphrase-999', $output);
        $this->assertStringNotContainsString('RecognisableMailPassword123', $output);
        $this->assertStringNotContainsString('a-test-vapid-private-key', $output);
        $this->assertStringNotContainsString((string) config('app.key'), $output);
    }

    public function test_it_exits_non_zero_when_backup_passphrase_is_missing(): void
    {
        config(['endorsement.instance.slug' => 'qch']);
        $this->fullyConfigure();
        putenv('BACKUP_PASSPHRASE=');

        $exitCode = Artisan::call('instance:show');

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('BACKUP_PASSPHRASE: NOT SET', Artisan::output());
    }

    public function test_it_exits_non_zero_when_mail_host_is_missing(): void
    {
        config(['endorsement.instance.slug' => 'qch']);
        $this->fullyConfigure();
        AppSettings::set('mail_host', null);

        $exitCode = Artisan::call('instance:show');

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString(
            "mail.default is still 'log'",
            Artisan::output(),
            'the consequence of a missing item should be named, not just its absence',
        );
    }

    public function test_it_exits_non_zero_when_alert_email_is_missing(): void
    {
        config(['endorsement.instance.slug' => 'qch']);
        $this->fullyConfigure();
        AppSettings::set('alert_email', null);

        $exitCode = Artisan::call('instance:show');

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString(
            'OpsAlert is log-only',
            Artisan::output(),
        );
    }

    public function test_it_exits_zero_when_fully_configured(): void
    {
        config(['endorsement.instance.slug' => 'qch']);
        $this->fullyConfigure();

        $exitCode = Artisan::call('instance:show');

        $this->assertSame(0, $exitCode);
    }

    /**
     * VAPID absence is silently degrading (push reminders never send), not blocking — it must
     * never be a reason the command reports an otherwise-live instance as not ready.
     */
    public function test_missing_vapid_keys_do_not_block_a_zero_exit(): void
    {
        config(['endorsement.instance.slug' => 'qch']);
        $this->fullyConfigure();
        AppSettings::set('vapid_public_key', null);
        AppSettings::set('vapid_private_key', null);

        $exitCode = Artisan::call('instance:show');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('vapid_public_key: NOT SET', Artisan::output());
    }
}
