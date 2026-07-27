<?php

namespace Tests\Feature\Admin;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AppSettings;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Admin → Settings: the runtime-configurable settings (SMTP, push/VAPID, reminder times)
 * editable in the app instead of the .env file. Rules that matter:
 *   - its own capability (`settings.manage`, Administrator-only by default);
 *   - SECRETS ARE WRITE-ONLY: stored encrypted at rest, never echoed back to any page,
 *     never audited by value (key names only), an empty submit means "keep current";
 *   - saved values override the mailer / push / reminder config at runtime.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['position' => 0]);
    }

    public function test_the_page_requires_the_settings_capability(): void
    {
        $this->get('/admin/settings')->assertRedirect('/login');

        $this->actingAs(User::factory()->create(['position' => 3]))
            ->get('/admin/settings')
            ->assertForbidden();

        $this->actingAs($this->admin())->get('/admin/settings')->assertOk();
    }

    public function test_smtp_settings_save_and_secrets_are_write_only(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/settings', [
            'mail_host' => 'smtp.example.org',
            'mail_port' => 587,
            'mail_username' => 'mailer@example.org',
            'mail_password' => 'super-secret-smtp',
            'mail_encryption' => 'tls',
            'mail_from_address' => 'endorsement@example.org',
            'mail_from_name' => 'Paediatric Endorsement',
        ])->assertRedirect();

        // Persisted, and the secret is encrypted at rest — the raw column never
        // contains the plaintext.
        $this->assertSame('smtp.example.org', AppSettings::get('mail_host'));
        $this->assertSame('super-secret-smtp', AppSettings::get('mail_password'));
        $raw = (string) DB::table('app_settings')->where('key', 'mail_password')->value('value');
        $this->assertStringNotContainsString('super-secret-smtp', $raw);

        // The page NEVER echoes the secret — only whether one is set.
        $this->actingAs($admin)->get('/admin/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.mail_host', 'smtp.example.org')
                ->where('secrets.mail_password', true)
                ->missing('settings.mail_password')
            );

        $response = $this->actingAs($admin)->get('/admin/settings');
        $this->assertStringNotContainsString('super-secret-smtp', $response->getContent());
    }

    public function test_an_empty_secret_submit_keeps_the_stored_value(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/settings', ['mail_password' => 'first-secret']);
        $this->actingAs($admin)->put('/admin/settings', ['mail_host' => 'smtp2.example.org', 'mail_password' => null]);

        $this->assertSame('first-secret', AppSettings::get('mail_password'));
        $this->assertSame('smtp2.example.org', AppSettings::get('mail_host'));
    }

    public function test_settings_changes_are_audited_by_key_never_by_value(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings', [
            'mail_host' => 'smtp.example.org',
            'mail_password' => 'super-secret-smtp',
        ]);

        $audit = AuditLog::where('action', 'settings_update')->latest('id')->firstOrFail();
        $this->assertStringContainsString('mail_host', (string) $audit->detail);
        $this->assertStringContainsString('mail_password', (string) $audit->detail);
        $this->assertStringNotContainsString('smtp.example.org', (string) $audit->detail);
        $this->assertStringNotContainsString('super-secret-smtp', (string) $audit->detail);
    }

    public function test_saved_settings_override_the_runtime_config(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings', [
            'mail_host' => 'smtp.live.example.org',
            'mail_from_address' => 'noreply@example.org',
            'vapid_public_key' => 'BPubKey123',
            'vapid_private_key' => 'PrivKey456',
            'remind_delay_minutes' => 15,
            'handover_time_morning' => '08:00',
            'handover_time_afternoon' => '14:00',
        ]);

        // The provider applies overrides at boot; apply explicitly to assert the mapping.
        AppSettings::applyOverrides();

        $this->assertSame('smtp.live.example.org', config('mail.mailers.smtp.host'));
        $this->assertSame('noreply@example.org', config('mail.from.address'));
        $this->assertSame('BPubKey123', config('endorsement.vapid.public_key'));
        $this->assertSame('PrivKey456', config('endorsement.vapid.private_key'));
        $this->assertSame(15, config('endorsement.remind_delay_minutes'));
        $this->assertSame(['08:00', '14:00'], config('endorsement.handover_times'));
    }

    public function test_storing_an_smtp_host_switches_the_mailer_away_from_log(): void
    {
        // The whole point of configuring SMTP here. Without it the owner fills in the form,
        // sees "Saved", and every operational alert — a failed backup, a broken audit chain
        // — is written to a log file instead of being delivered. Silently: no error, no
        // bounce, nothing to notice. The runbook deliberately keeps mail OUT of the
        // environment, so this screen is the only thing that can turn the mailer on.
        config(['mail.default' => 'log']);
        AppSettings::set('mail_host', 'smtp.live.example.org');

        AppSettings::applyOverrides();

        $this->assertSame('smtp', config('mail.default'));
    }

    public function test_the_mailer_stays_on_log_until_a_host_is_stored(): void
    {
        // The other half: never select a transport that has nowhere to connect to. Login,
        // password reset and registration all send mail unguarded, so switching to smtp
        // with no host would turn those into 500s rather than silent no-ops.
        config(['mail.default' => 'log']);

        AppSettings::applyOverrides();

        $this->assertSame('log', config('mail.default'));
    }

    public function test_reminder_settings_are_validated(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/settings', ['handover_time_morning' => '25:99'])
            ->assertSessionHasErrors('handover_time_morning');
        $this->actingAs($admin)->put('/admin/settings', ['remind_delay_minutes' => 999])
            ->assertSessionHasErrors('remind_delay_minutes');
        $this->actingAs($admin)->put('/admin/settings', ['mail_port' => 'not-a-port'])
            ->assertSessionHasErrors('mail_port');
    }

    public function test_unknown_setting_keys_are_rejected_not_stored(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings', [
            'mail_host' => 'smtp.example.org',
            'evil_key' => 'x',
        ])->assertRedirect(); // unknown fields are simply ignored by validation

        $this->assertNull(AppSetting::where('key', 'evil_key')->first());
    }
}
