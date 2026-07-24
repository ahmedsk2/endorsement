<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Drive a login with an expired password so the forced-change session flag is set,
     * then return the user.
     */
    private function userWithExpiredPassword(): User
    {
        $user = User::factory()->create([
            'member_name' => 'dr_old',
            'password' => 'old-pass1',
            'active' => true,
            'pass_exp_date' => now()->subMonths(4)->toDateString(),
        ]);

        $this->post('/login', ['member_name' => 'dr_old', 'password' => 'old-pass1'])
            ->assertRedirect(route('password.change'));

        return $user;
    }

    public function test_change_password_screen_renders_for_a_forced_user(): void
    {
        $this->userWithExpiredPassword();

        $this->get('/change-password')->assertOk();
    }

    public function test_change_password_without_a_pending_flag_redirects_to_login(): void
    {
        $this->get('/change-password')->assertRedirect(route('login'));
    }

    public function test_forced_change_updates_the_hash_and_redirects_to_login(): void
    {
        $user = $this->userWithExpiredPassword();

        $response = $this->post('/change-password', [
            'current_password' => 'old-pass1',
            'password' => 'brand-new123',
            'password_confirmation' => 'brand-new123',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('brand-new123', $user->fresh()->password));
        $this->assertGuest();
    }

    public function test_forced_change_rejects_a_wrong_current_password(): void
    {
        $this->userWithExpiredPassword();

        $response = $this->from('/change-password')->post('/change-password', [
            'current_password' => 'not-the-old-one',
            'password' => 'brand-new123',
            'password_confirmation' => 'brand-new123',
        ]);

        $response->assertSessionHasErrors('current_password');
    }
}
