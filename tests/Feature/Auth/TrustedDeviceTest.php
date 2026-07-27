<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\TrustedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * "Don't ask for a code on this device for 7 days."
 *
 * Convenience that removes an authentication step needs its limits pinned, not assumed.
 * The three that matter, each tested below:
 *
 *  - it skips the SECOND factor only — never the password, never the active check;
 *  - it is scoped to the account it was minted for, so a cookie left on a shared ward
 *    workstation cannot carry the next clinician past their own factor;
 *  - it ends when someone signals that something is wrong — a password change, or turning
 *    the factor off.
 */
class TrustedDeviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ReferenceSeeder::class);
        $this->seed(\Database\Seeders\AccessControlSeeder::class);
    }

    private function totpUser(string $password = 'Str0ng!Passw0rd'): User
    {
        $secret = (new Google2FA)->generateSecretKey();

        return User::factory()->create([
            'active' => true,
            'password' => Hash::make($password),
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_method' => 'totp',
        ]);
    }

    private function signIn(User $user, string $password = 'Str0ng!Passw0rd')
    {
        return $this->post('/login', ['member_name' => $user->member_name, 'password' => $password]);
    }

    public function test_without_a_trusted_device_the_code_is_still_demanded(): void
    {
        $user = $this->totpUser();

        $this->signIn($user)->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_a_trusted_device_skips_the_code(): void
    {
        $user = $this->totpUser();
        TrustedDevice::remember($user, 'Test Browser');

        $token = $this->trustedToken();

        $this->withUnencryptedCookie(TrustedDevice::COOKIE, $token)
            ->signIn($user)
            ->assertRedirect('/endorsement');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_log', ['action' => 'login_trusted_device']);
    }

    public function test_it_never_skips_the_password(): void
    {
        $user = $this->totpUser();
        TrustedDevice::remember($user, 'Test Browser');

        $this->withUnencryptedCookie(TrustedDevice::COOKIE, $this->trustedToken())
            ->post('/login', ['member_name' => $user->member_name, 'password' => 'wrong-password'])
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_it_never_skips_the_active_check(): void
    {
        $user = $this->totpUser();
        TrustedDevice::remember($user, 'Test Browser');
        $token = $this->trustedToken();

        $user->forceFill(['active' => false])->save();

        $this->withUnencryptedCookie(TrustedDevice::COOKIE, $token)
            ->signIn($user)
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_one_persons_device_cookie_does_not_carry_another_person(): void
    {
        // The shared ward workstation. Without the user_id scope on the lookup, the second
        // clinician to sign in on this machine would silently skip their own second factor.
        $alpha = $this->totpUser();
        $beta = $this->totpUser();

        TrustedDevice::remember($alpha, 'Ward PC');
        $alphasToken = $this->trustedToken();

        $this->withUnencryptedCookie(TrustedDevice::COOKIE, $alphasToken)
            ->signIn($beta)
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    public function test_an_expired_trust_is_refused(): void
    {
        $user = $this->totpUser();
        TrustedDevice::remember($user, 'Old Browser');
        $token = $this->trustedToken();

        \Illuminate\Support\Facades\DB::table('trusted_devices')
            ->update(['expires_at' => now()->subMinute()]);

        $this->withUnencryptedCookie(TrustedDevice::COOKIE, $token)
            ->signIn($user)
            ->assertRedirect(route('two-factor.login'));
    }

    public function test_a_forged_token_is_refused(): void
    {
        $user = $this->totpUser();
        TrustedDevice::remember($user, 'Real Browser');

        $this->withUnencryptedCookie(TrustedDevice::COOKIE, str_repeat('a', 64))
            ->signIn($user)
            ->assertRedirect(route('two-factor.login'));
    }

    public function test_the_token_is_not_stored_in_plaintext(): void
    {
        $user = $this->totpUser();
        TrustedDevice::remember($user, 'Test Browser');

        $token = $this->trustedToken();
        $stored = (string) \Illuminate\Support\Facades\DB::table('trusted_devices')->value('token_hash');

        $this->assertNotSame($token, $stored);
        $this->assertSame(hash('sha256', $token), $stored);
    }

    public function test_changing_the_password_ends_trust_everywhere(): void
    {
        // The one action a worried user knows to take must not leave a week of skipped
        // codes outstanding on machines they no longer trust.
        $user = $this->totpUser();
        TrustedDevice::remember($user, 'Device A');
        TrustedDevice::remember($user, 'Device B');

        $this->assertSame(2, TrustedDevice::countFor($user));

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'Str0ng!Passw0rd',
            'password' => 'An0ther!Passw0rd',
            'password_confirmation' => 'An0ther!Passw0rd',
        ])->assertRedirect();

        $this->assertSame(0, TrustedDevice::countFor($user));
    }

    public function test_turning_the_authenticator_off_ends_trust_everywhere(): void
    {
        $user = $this->totpUser();
        TrustedDevice::remember($user, 'Device A');

        $this->actingAs($user)->delete('/user/two-factor')->assertRedirect();

        $this->assertSame(0, TrustedDevice::countFor($user));
    }

    /** The plaintext token that TrustedDevice::remember just queued in a cookie. */
    private function trustedToken(): string
    {
        foreach (\Illuminate\Support\Facades\Cookie::getQueuedCookies() as $cookie) {
            if ($cookie->getName() === TrustedDevice::COOKIE) {
                return (string) $cookie->getValue();
            }
        }

        $this->fail('no trusted-device cookie was queued');
    }
}
