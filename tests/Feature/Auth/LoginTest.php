<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    }

    public function test_valid_credentials_authenticate_and_write_a_login_audit_row(): void
    {
        $user = User::factory()->create([
            'member_name' => 'dr_ali',
            'password' => 'secret-pass1',
            'active' => true,
        ]);

        $response = $this->post('/login', [
            'member_name' => 'dr_ali',
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

    public function test_invalid_credentials_return_generic_error_without_authenticating(): void
    {
        User::factory()->create([
            'member_name' => 'dr_ali',
            'password' => 'secret-pass1',
            'active' => true,
        ]);

        $response = $this->from('/login')->post('/login', [
            'member_name' => 'dr_ali',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['member_name' => 'Invalid Login']);
        $this->assertGuest();
    }

    public function test_unknown_member_name_returns_generic_error(): void
    {
        $response = $this->from('/login')->post('/login', [
            'member_name' => 'nobody',
            'password' => 'whatever12',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['member_name' => 'Invalid Login']);
        $this->assertGuest();
    }

    public function test_inactive_user_is_blocked_with_activation_message(): void
    {
        User::factory()->inactive()->create([
            'member_name' => 'dr_new',
            'password' => 'secret-pass1',
        ]);

        $response = $this->from('/login')->post('/login', [
            'member_name' => 'dr_new',
            'password' => 'secret-pass1',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['member_name' => 'Need for activation, contact your manager']);
        $this->assertGuest();
        $this->assertDatabaseMissing('audit_log', ['action' => 'login']);
    }

    public function test_expired_password_forces_change_and_does_not_authenticate(): void
    {
        User::factory()->create([
            'member_name' => 'dr_old',
            'password' => 'secret-pass1',
            'active' => true,
            'pass_exp_date' => now()->subMonths(4)->toDateString(),
        ]);

        $response = $this->post('/login', [
            'member_name' => 'dr_old',
            'password' => 'secret-pass1',
        ]);

        $response->assertRedirect(route('password.change'));
        $this->assertGuest();
        $this->assertDatabaseMissing('audit_log', ['action' => 'login']);
    }

    public function test_non_expired_password_within_three_months_logs_in(): void
    {
        $user = User::factory()->create([
            'member_name' => 'dr_recent',
            'password' => 'secret-pass1',
            'active' => true,
            'pass_exp_date' => now()->subMonths(2)->toDateString(),
        ]);

        $response = $this->post('/login', [
            'member_name' => 'dr_recent',
            'password' => 'secret-pass1',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_successful_login_lands_on_the_endorsement_chooser(): void
    {
        // Found by running the app: login previously landed on the `/` Welcome placeholder.
        User::factory()->create([
            'member_name' => 'dr_land',
            'password' => 'secret-pass1',
            'active' => true,
        ]);

        $this->post('/login', ['member_name' => 'dr_land', 'password' => 'secret-pass1'])
            ->assertRedirect('/endorsement');
    }

    public function test_login_locks_out_after_too_many_failed_attempts(): void
    {
        User::factory()->create([
            'member_name' => 'dr_lock',
            'password' => 'correct-pass1',
            'active' => true,
        ]);

        // Exhaust the allowed attempts with wrong passwords.
        for ($i = 0; $i < AuthenticatedSessionController::MAX_ATTEMPTS; $i++) {
            $this->post('/login', ['member_name' => 'dr_lock', 'password' => 'wrong-pass']);
        }

        // Even the CORRECT password is now refused with the lockout message, and no login.
        $response = $this->post('/login', ['member_name' => 'dr_lock', 'password' => 'correct-pass1']);

        $response->assertSessionHasErrors('member_name');
        $this->assertGuest();
        $this->assertStringContainsString(
            'Too many login attempts',
            session('errors')->get('member_name')[0]
        );
    }
}
