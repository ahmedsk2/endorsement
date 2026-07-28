<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Support\AppSettings;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The leaver backstop.
 *
 * This system cannot be told when a clinician leaves — that is organisational, and the
 * agreed control is a review of the active-account list. But that review is ANNUAL, so an
 * account belonging to someone who left can stay live for up to twelve months, and with
 * rotating residents that is not hypothetical.
 *
 * The system cannot know who left. It does know who has not been here, and "active, and
 * untouched for three months" is the shape a departed clinician's account has. This is a
 * prompt to look — never an automatic deactivation, because a doctor returning from long
 * leave to a disabled account is a worse failure than the one being prevented.
 */
class DormantAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);

        Mail::fake();
        AppSettings::set('alert_email', 'oncall@example.org');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.org']);
    }

    public function test_an_account_idle_past_the_threshold_is_reported(): void
    {
        User::factory()->create(['active' => true, 'last_login_at' => now()->subDays(120)]);

        $this->artisan('users:dormant')->assertExitCode(0);

        Mail::assertSent(\App\Mail\OpsAlertMail::class);
    }

    public function test_an_account_in_regular_use_is_not(): void
    {
        User::factory()->create(['active' => true, 'last_login_at' => now()->subDays(3)]);

        $this->artisan('users:dormant')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_an_account_that_never_signed_in_is_judged_from_its_creation(): void
    {
        // An invitation accepted and then never used is the same concern, and arguably a
        // sharper one — it has never been the person it was issued to.
        $never = User::factory()->create(['active' => true, 'last_login_at' => null]);
        $never->forceFill(['created_at' => now()->subDays(200)])->saveQuietly();

        $this->artisan('users:dormant')->assertExitCode(0);

        Mail::assertSent(\App\Mail\OpsAlertMail::class);
    }

    public function test_a_newly_invited_account_is_given_time_to_be_used(): void
    {
        // Created yesterday, never signed in. Alerting on this would fire on every
        // invitation and train its reader to ignore the whole check.
        $fresh = User::factory()->create(['active' => true, 'last_login_at' => null]);
        $fresh->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $this->artisan('users:dormant')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_an_already_deactivated_account_is_not_reported(): void
    {
        // The action this check asks for has already been taken; repeating it would be noise.
        User::factory()->create(['active' => false, 'last_login_at' => now()->subDays(400)]);

        $this->artisan('users:dormant')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_the_alert_names_nobody(): void
    {
        // It travels by email to an external mailbox. A list of who is absent from a
        // children's hospital is not something to put in one — ids and counts only, the
        // same rule the audit trail follows.
        User::factory()->create([
            'active' => true,
            'full_name' => 'Dr Departed Person',
            'member_email' => 'departed@example.org',
            'member_name' => 'departed',
            'last_login_at' => now()->subDays(120),
        ]);

        $this->artisan('users:dormant');

        Mail::assertSent(\App\Mail\OpsAlertMail::class, function ($mail) {
            $body = (string) $mail->render();

            $this->assertStringNotContainsString('Dr Departed Person', $body);
            $this->assertStringNotContainsString('departed@example.org', $body);
            $this->assertStringNotContainsString('departed', $body);

            return true;
        });
    }
}
