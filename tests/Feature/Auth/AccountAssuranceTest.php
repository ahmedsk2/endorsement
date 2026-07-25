<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Models\AuditLog;
use App\Models\LoginOtp;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Notifications\LoginOtpCode;
use App\Notifications\VerifyEmailAddress;
use App\Support\EmailOtp;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Account assurance (owner request, 2026-07-25): confirm the email address on
 * registration, choose a second factor (authenticator app OR emailed code), and change
 * your own password once signed in.
 */
class AccountAssuranceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
        Notification::fake();
    }

    private function register(array $overrides = []): PendingRegistration
    {
        $this->post('/register', array_merge([
            'full_name' => 'New Resident',
            'member_name' => 'new_res',
            'member_email' => 'new_res@example.org',
            'position' => 4,
            'password' => 'Secret-pass1',
            'password_confirmation' => 'Secret-pass1',
        ], $overrides))->assertRedirect();

        return PendingRegistration::latest('id')->firstOrFail();
    }

    // ------------------------------------------------------- email confirmation

    public function test_registration_sends_a_confirmation_link_and_starts_unverified(): void
    {
        $pending = $this->register();

        $this->assertNull($pending->email_verified_at);
        Notification::assertSentOnDemand(VerifyEmailAddress::class);
    }

    public function test_opening_the_signed_link_confirms_the_registration(): void
    {
        $pending = $this->register();

        $this->get(EmailVerificationController::registrationUrl($pending))
            ->assertRedirect(route('login'));

        $this->assertNotNull($pending->fresh()->email_verified_at);

        // Audited by id — the address itself is personal data and stays out of the trail.
        $audit = AuditLog::where('action', 'registration_email_verified')->latest('id')->firstOrFail();
        $this->assertStringContainsString('pending='.$pending->id, (string) $audit->detail);
        $this->assertStringNotContainsString('new_res@example.org', (string) $audit->detail);
    }

    public function test_an_unsigned_or_tampered_link_is_refused(): void
    {
        $pending = $this->register();

        // No signature at all.
        $this->get('/registration/verify/'.$pending->id.'/'.sha1((string) $pending->member_email))
            ->assertForbidden();

        // Correctly signed, but for a different address than the row now holds.
        $url = URL::temporarySignedRoute('registration.verify', now()->addHour(), [
            'pending' => $pending->id,
            'hash' => sha1('someone.else@example.org'),
        ]);
        $this->get($url)->assertForbidden();

        $this->assertNull($pending->fresh()->email_verified_at);
    }

    public function test_an_expired_link_is_refused(): void
    {
        $pending = $this->register();

        $url = URL::temporarySignedRoute('registration.verify', now()->subMinute(), [
            'pending' => $pending->id,
            'hash' => sha1((string) $pending->member_email),
        ]);

        $this->get($url)->assertForbidden();
        $this->assertNull($pending->fresh()->email_verified_at);
    }

    public function test_a_signed_in_user_can_confirm_their_own_address(): void
    {
        $user = User::factory()->create(['member_email' => 'me@example.org', 'email_verified_at' => null]);

        $this->actingAs($user)->post('/profile/email/verify')->assertRedirect();
        Notification::assertSentOnDemand(VerifyEmailAddress::class);

        $this->actingAs($user)
            ->get(EmailVerificationController::accountUrl($user))
            ->assertRedirect(route('profile.edit'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    // ------------------------------------------------------------ second factor

    public function test_email_can_only_be_chosen_as_a_factor_once_the_address_is_confirmed(): void
    {
        $user = User::factory()->create(['member_email' => 'me@example.org', 'email_verified_at' => null]);

        $this->actingAs($user)->put('/profile/two-factor-method', ['method' => 'email'])
            ->assertSessionHasErrors('method');
        $this->assertNull($user->fresh()->two_factor_method);

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($user)->put('/profile/two-factor-method', ['method' => 'email'])
            ->assertSessionHasNoErrors();
        $this->assertSame('email', $user->fresh()->two_factor_method);
    }

    public function test_totp_cannot_be_chosen_before_it_is_enrolled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/profile/two-factor-method', ['method' => 'totp'])
            ->assertSessionHasErrors('method');
    }

    public function test_a_login_with_the_email_factor_issues_a_code_instead_of_signing_in(): void
    {
        $user = User::factory()->create([
            'member_name' => 'otp_user',
            'member_email' => 'otp@example.org',
            'email_verified_at' => now(),
            'two_factor_method' => 'email',
            'password' => 'Secret-pass1',
            'active' => true,
        ]);

        $this->post('/login', ['member_name' => 'otp_user', 'password' => 'Secret-pass1'])
            ->assertRedirect(route('email-otp.login'));

        $this->assertGuest();
        $this->assertDatabaseCount('login_otps', 1);
        Notification::assertSentOnDemand(LoginOtpCode::class);

        // The code is stored HASHED — a database read yields nothing usable.
        $otp = LoginOtp::firstOrFail();
        $this->assertNotSame('', $otp->code_hash);
        $this->assertMatchesRegularExpression('/^\$2y\$/', $otp->code_hash);
    }

    public function test_the_correct_code_completes_the_login_once_and_only_once(): void
    {
        $user = User::factory()->create([
            'member_name' => 'otp_user2',
            'member_email' => 'otp2@example.org',
            'email_verified_at' => now(),
            'two_factor_method' => 'email',
            'password' => 'Secret-pass1',
        ]);

        // Drive the real flow, then plant a known code (the mailed one is not readable here).
        $this->post('/login', ['member_name' => 'otp_user2', 'password' => 'Secret-pass1']);
        LoginOtp::where('user_id', $user->id)->update(['code_hash' => Hash::make('123456')]);

        $this->post('/email-code', ['code' => '123456'])->assertRedirect('/endorsement');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('login_otps', 0);   // single use

        $this->assertNotNull(AuditLog::where('action', 'login_two_factor_email')->first());
    }

    public function test_a_wrong_code_does_not_sign_in_and_is_capped(): void
    {
        $user = User::factory()->create([
            'member_name' => 'otp_user3',
            'member_email' => 'otp3@example.org',
            'email_verified_at' => now(),
            'two_factor_method' => 'email',
            'password' => 'Secret-pass1',
        ]);

        $this->post('/login', ['member_name' => 'otp_user3', 'password' => 'Secret-pass1']);
        LoginOtp::where('user_id', $user->id)->update(['code_hash' => Hash::make('123456')]);

        $this->post('/email-code', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        // The session budget is finite: repeated failures end the challenge entirely.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/email-code', ['code' => '000000']);
        }

        $this->post('/email-code', ['code' => '123456']);
        $this->assertGuest();
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = User::factory()->create(['member_email' => 'x@example.org', 'email_verified_at' => now()]);

        EmailOtp::issue($user);
        LoginOtp::where('user_id', $user->id)->update([
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertFalse(EmailOtp::verify($user->fresh(), '123456'));
        $this->assertDatabaseCount('login_otps', 0);
    }

    public function test_a_deactivated_account_cannot_finish_the_email_challenge(): void
    {
        $user = User::factory()->create([
            'member_name' => 'otp_user4',
            'member_email' => 'otp4@example.org',
            'email_verified_at' => now(),
            'two_factor_method' => 'email',
            'password' => 'Secret-pass1',
        ]);

        $this->post('/login', ['member_name' => 'otp_user4', 'password' => 'Secret-pass1']);
        LoginOtp::where('user_id', $user->id)->update(['code_hash' => Hash::make('123456')]);

        $user->forceFill(['active' => false])->save();

        $this->post('/email-code', ['code' => '123456'])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // --------------------------------------------------------- password change

    public function test_a_user_changes_their_own_password_with_the_current_one(): void
    {
        $user = User::factory()->create(['password' => 'Secret-pass1']);

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'Secret-pass1',
            'password' => 'Brand-new-pass2',
            'password_confirmation' => 'Brand-new-pass2',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Brand-new-pass2', $user->fresh()->getAuthPassword()));
        // The 3-month expiry clock restarts.
        $this->assertSame(today()->format('Y-m-d'), $user->fresh()->pass_exp_date->format('Y-m-d'));
        $this->assertNotNull(AuditLog::where('action', 'password_changed')->first());
    }

    public function test_the_wrong_current_password_is_refused(): void
    {
        $user = User::factory()->create(['password' => 'Secret-pass1']);

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'not-my-password',
            'password' => 'Brand-new-pass2',
            'password_confirmation' => 'Brand-new-pass2',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('Secret-pass1', $user->fresh()->getAuthPassword()));
    }

    public function test_the_new_password_must_meet_the_policy_and_differ(): void
    {
        $user = User::factory()->create(['password' => 'Secret-pass1']);

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'Secret-pass1',
            'password' => 'weakpass',
            'password_confirmation' => 'weakpass',
        ])->assertSessionHasErrors('password');

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'Secret-pass1',
            'password' => 'Secret-pass1',
            'password_confirmation' => 'Secret-pass1',
        ])->assertSessionHasErrors('password');
    }

    public function test_changing_the_password_signs_other_devices_out(): void
    {
        $user = User::factory()->create(['password' => 'Secret-pass1']);

        \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id' => 'another-device-session',
            'user_id' => $user->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'other',
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'Secret-pass1',
            'password' => 'Brand-new-pass2',
            'password_confirmation' => 'Brand-new-pass2',
        ]);

        $this->assertDatabaseMissing('sessions', ['id' => 'another-device-session']);
    }
}
