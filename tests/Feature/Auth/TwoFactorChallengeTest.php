<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * B4 — the login-time second-factor challenge. A user WITH confirmed 2FA is parked at the
 * challenge (not authenticated) after the password step; a valid TOTP or a single-use
 * recovery code completes the login. Users WITHOUT 2FA log in exactly as before.
 */
class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    private function google2fa(): Google2FA
    {
        return new Google2FA;
    }

    /** Create an active user with confirmed 2FA and the given secret + recovery codes. */
    private function userWithTwoFactor(string $secret, array $recoveryCodes = ['AAAA-BBBB', 'CCCC-DDDD']): User
    {
        $user = User::factory()->create([
            'member_name' => 'dr_2fa',
            'password' => 'secret-pass1',
            'active' => true,
        ]);

        $user->update([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ]);

        return $user;
    }

    public function test_user_with_two_factor_is_redirected_to_challenge_and_not_authenticated(): void
    {
        $user = $this->userWithTwoFactor($this->google2fa()->generateSecretKey());

        $response = $this->post('/login', [
            'member_name' => 'dr_2fa',
            'password' => 'secret-pass1',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        // No login audit row is written until the challenge is passed.
        $this->assertDatabaseMissing('audit_log', ['action' => 'login', 'user_id' => $user->id]);
    }

    public function test_challenge_screen_renders_only_while_a_login_is_pending(): void
    {
        // No pending login -> bounce to /login.
        $this->get(route('two-factor.login'))->assertRedirect('/login');

        $this->userWithTwoFactor($this->google2fa()->generateSecretKey());
        $this->post('/login', ['member_name' => 'dr_2fa', 'password' => 'secret-pass1']);

        $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/TwoFactorChallenge'));
    }

    public function test_challenge_completes_with_a_valid_totp_and_writes_the_login_audit_row(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);

        $this->post('/login', ['member_name' => 'dr_2fa', 'password' => 'secret-pass1'])
            ->assertRedirect(route('two-factor.login'));

        $response = $this->post('/two-factor-challenge', [
            'code' => $this->google2fa()->getCurrentOtp($secret),
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'login',
            'user_id' => $user->id,
            'detail' => 'member='.$user->id,
        ]);
    }

    public function test_challenge_rejects_an_invalid_totp_without_authenticating(): void
    {
        $this->userWithTwoFactor($this->google2fa()->generateSecretKey());

        $this->post('/login', ['member_name' => 'dr_2fa', 'password' => 'secret-pass1']);

        $response = $this->from(route('two-factor.login'))
            ->post('/two-factor-challenge', ['code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_recovery_code_works_once_and_is_then_consumed(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret, ['AAAA-BBBB', 'CCCC-DDDD']);

        // Pass the password step, then redeem a recovery code.
        $this->post('/login', ['member_name' => 'dr_2fa', 'password' => 'secret-pass1']);
        $this->post('/two-factor-challenge', ['code' => 'AAAA-BBBB'])->assertRedirect();
        $this->assertAuthenticatedAs($user);

        // The redeemed code is gone from the stored set.
        $remaining = $user->fresh()->two_factor_recovery_codes;
        $this->assertNotContains('AAAA-BBBB', $remaining);
        $this->assertContains('CCCC-DDDD', $remaining);

        // Log out and try the SAME recovery code again -> rejected.
        $this->post('/logout');
        $this->post('/login', ['member_name' => 'dr_2fa', 'password' => 'secret-pass1']);
        $response = $this->from(route('two-factor.login'))
            ->post('/two-factor-challenge', ['code' => 'AAAA-BBBB']);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_user_without_two_factor_logs_in_unchanged(): void
    {
        $user = User::factory()->create([
            'member_name' => 'dr_no2fa',
            'password' => 'secret-pass1',
            'active' => true,
        ]);

        $response = $this->post('/login', [
            'member_name' => 'dr_no2fa',
            'password' => 'secret-pass1',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'login',
            'user_id' => $user->id,
            'detail' => 'member='.$user->id,
        ]);
    }

    public function test_a_totp_code_cannot_be_replayed_and_a_failed_attempt_is_audited(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);
        $otp = $this->google2fa()->getCurrentOtp($secret);

        // First login + challenge with the OTP succeeds.
        $this->post('/login', ['member_name' => 'dr_2fa', 'password' => 'secret-pass1']);
        $this->post('/two-factor-challenge', ['code' => $otp])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');

        // A fresh login parks the identity again; the SAME still-valid OTP is refused as a
        // replay, and the failed attempt is recorded (PHI-free) for brute-force detection.
        $this->post('/login', ['member_name' => 'dr_2fa', 'password' => 'secret-pass1']);
        $response = $this->from(route('two-factor.login'))
            ->post('/two-factor-challenge', ['code' => $otp]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_log', [
            'action' => '2fa_failed',
            'user_id' => $user->id,
            'detail' => 'member='.$user->id,
        ]);
    }

    public function test_a_persistent_per_user_lockout_blocks_the_challenge_even_with_a_valid_code(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);

        // Simulate the per-user failure limiter already exhausted across earlier sessions.
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('two-factor-challenge:'.$user->id, 900);
        }

        $this->post('/login', ['member_name' => 'dr_2fa', 'password' => 'secret-pass1']);
        $response = $this->from(route('two-factor.login'))
            ->post('/two-factor-challenge', ['code' => $this->google2fa()->getCurrentOtp($secret)]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertStringContainsString(
            'Too many incorrect codes. Please try again',
            session('errors')->get('code')[0]
        );
    }
}
