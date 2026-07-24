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
}
