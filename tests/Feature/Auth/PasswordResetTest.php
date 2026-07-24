<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_screen_renders(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_reset_link_request_creates_a_token_row(): void
    {
        User::factory()->create(['member_email' => 'doc@example.com', 'active' => true]);

        $response = $this->from('/forgot-password')->post('/forgot-password', [
            'email' => 'doc@example.com',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'doc@example.com']);
    }

    public function test_reset_link_request_does_not_leak_unknown_accounts(): void
    {
        $response = $this->from('/forgot-password')->post('/forgot-password', [
            'email' => 'ghost@example.com',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'ghost@example.com']);
    }

    public function test_reset_password_screen_renders(): void
    {
        $this->get('/reset-password/some-token?email=doc@example.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ResetPassword'));
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->create([
            'member_email' => 'doc@example.com',
            'password' => 'old-pass1',
            'active' => true,
        ]);

        $token = Password::createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => 'doc@example.com',
            'password' => 'new-pass123',
            'password_confirmation' => 'new-pass123',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('new-pass123', $user->fresh()->password));
    }
}
