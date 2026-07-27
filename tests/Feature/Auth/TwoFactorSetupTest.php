<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * B4 — MFA (TOTP) enrollment on the user's own profile: generate/confirm/disable,
 * and the at-rest encryption of the secret + recovery codes.
 */
class TwoFactorSetupTest extends TestCase
{
    use RefreshDatabase;

    private function google2fa(): Google2FA
    {
        return new Google2FA;
    }

    public function test_the_two_factor_page_is_not_cached(): void
    {
        $user = User::factory()->create(['active' => true]);

        // The page can carry the one-time plaintext secret + recovery codes, so it must never
        // be cached by the browser or intermediaries.
        $response = $this->actingAs($user)->get('/user/two-factor');

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_enabling_generates_a_secret_and_eight_recovery_codes_not_yet_confirmed(): void
    {
        $user = User::factory()->create(['active' => true]);

        $response = $this->actingAs($user)->post('/user/two-factor');

        $response->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertIsArray($user->two_factor_recovery_codes);
        $this->assertCount(8, $user->two_factor_recovery_codes);
        // Enrollment is pending until a valid code confirms it.
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_the_stored_secret_and_recovery_codes_are_encrypted_at_rest(): void
    {
        $user = User::factory()->create(['active' => true]);

        $this->actingAs($user)->post('/user/two-factor');
        $user->refresh();

        $plaintextSecret = $user->two_factor_secret;          // cast-decrypted
        $firstCode = $user->two_factor_recovery_codes[0];

        $rawSecret = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $rawCodes = DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');

        $this->assertNotNull($rawSecret);
        // The raw column value must NOT be (or contain) the plaintext secret / codes.
        $this->assertNotSame($plaintextSecret, $rawSecret);
        $this->assertStringNotContainsString($plaintextSecret, $rawSecret);
        $this->assertStringNotContainsString($firstCode, (string) $rawCodes);
    }

    public function test_confirming_with_a_valid_totp_code_activates_two_factor(): void
    {
        $user = User::factory()->create(['active' => true]);
        $this->actingAs($user)->post('/user/two-factor');
        $user->refresh();

        $code = $this->google2fa()->getCurrentOtp($user->two_factor_secret);

        $response = $this->actingAs($user)->post('/user/two-factor/confirm', ['code' => $code]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_confirming_with_an_invalid_code_does_not_activate_two_factor(): void
    {
        $user = User::factory()->create(['active' => true]);
        $this->actingAs($user)->post('/user/two-factor');

        $response = $this->from('/user/two-factor')->actingAs($user)
            ->post('/user/two-factor/confirm', ['code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_disabling_clears_all_three_two_factor_columns(): void
    {
        $user = User::factory()->create(['active' => true]);
        $this->actingAs($user)->post('/user/two-factor');
        $user->refresh();
        $this->actingAs($user)->post('/user/two-factor/confirm', [
            'code' => $this->google2fa()->getCurrentOtp($user->two_factor_secret),
        ]);
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        $response = $this->actingAs($user)->delete('/user/two-factor');

        $response->assertRedirect();
        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_two_factor_management_requires_authentication(): void
    {
        $this->post('/user/two-factor')->assertRedirect('/login');
    }

    /**
     * Confirming an enrolment must ACTIVATE it — the second save is the app's job.
     *
     * Reported by the owner as "I had to save multiple times", and it was not clumsiness.
     * Enrolment set `two_factor_confirmed_at`; the METHOD lived on a different route and
     * was left null. So the first save (choosing "Authenticator app" on the profile before
     * enrolling) was rejected by design, and after enrolling the user had to return to the
     * profile and choose it a second time — with nothing on the enrolment page saying so,
     * or even linking back.
     *
     * Worse, the state in between was self-contradictory: sign-in DID demand a TOTP code
     * (AuthenticatedSessionController's legacy fallback), while EnforceTwoFactor read the
     * null method and kept telling the same user they had not set up a second factor.
     */
    public function test_confirming_an_enrolment_activates_it_without_a_second_save(): void
    {
        $user = User::factory()->create(['active' => true]);

        $this->actingAs($user)->post('/user/two-factor')->assertRedirect();
        $user->refresh();

        $this->actingAs($user)->post('/user/two-factor/confirm', [
            'code' => $this->google2fa()->getCurrentOtp($user->two_factor_secret),
        ])->assertRedirect();

        $user->refresh();

        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertSame('totp', $user->two_factor_method);
        // The predicate the rest of the app actually consults.
        $this->assertSame('totp', $user->activeTwoFactorMethod());
    }

    public function test_a_failed_confirmation_activates_nothing(): void
    {
        $user = User::factory()->create(['active' => true]);

        $this->actingAs($user)->post('/user/two-factor')->assertRedirect();

        $this->actingAs($user)->post('/user/two-factor/confirm', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $user->refresh();

        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_method);
    }

    public function test_disabling_the_authenticator_also_stands_the_method_down(): void
    {
        // Otherwise the account keeps method='totp' with no secret. activeTwoFactorMethod()
        // returns null, so a privileged user is bounced to the profile on every page with a
        // banner saying they have no second factor, while the profile radio insists they
        // chose the authenticator. That is the same contradiction from the other direction.
        $user = User::factory()->create(['active' => true]);

        $this->actingAs($user)->post('/user/two-factor');
        $user->refresh();
        $this->actingAs($user)->post('/user/two-factor/confirm', [
            'code' => $this->google2fa()->getCurrentOtp($user->two_factor_secret),
        ]);

        $this->actingAs($user)->delete('/user/two-factor')->assertRedirect();

        $user->refresh();

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_method);
    }

    public function test_an_email_method_is_left_alone_when_the_authenticator_is_disabled(): void
    {
        // Standing down TOTP must not silently switch off a second factor the user is
        // actually relying on.
        $user = User::factory()->create([
            'active' => true,
            'email_verified_at' => now(),
            'two_factor_method' => 'email',
        ]);

        $this->actingAs($user)->delete('/user/two-factor')->assertRedirect();

        $this->assertSame('email', $user->fresh()->two_factor_method);
    }
}
