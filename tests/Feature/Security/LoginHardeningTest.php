<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Support\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginHardeningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SPC-TM-009. Deprovisioning depended entirely on somebody remembering: no dormancy
     * signal existed anywhere, and paediatric residents rotate on a schedule while the
     * account roster does not.
     */
    public function test_signing_in_records_when_the_account_was_last_used(): void
    {
        $user = User::factory()->create([
            'member_name' => 'resident1',
            'password' => 'Str0ng-pass!x',
            'position' => 4,
            'active' => true,
            'pass_exp_date' => now()->addYear()->format('Y-m-d'),
        ]);

        $this->assertNull($user->last_login_at);

        $this->post('/login', ['member_name' => 'resident1', 'password' => 'Str0ng-pass!x']);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    /**
     * SPC-TM-006. The session idle lifetime is 60 minutes BECAUSE these are shared ward
     * workstations — and Laravel's default recaller cookie runs to roughly 400 days, which
     * silently re-authenticates whoever sits down next for the best part of a year.
     */
    public function test_remember_me_is_capped_to_a_shift_not_a_year(): void
    {
        $this->assertLessThanOrEqual(
            60 * 24,
            Login::REMEMBER_MINUTES,
            'remember-me must not outlive a single shift on a shared workstation',
        );
    }

    public function test_a_signed_in_session_still_works_normally(): void
    {
        $user = User::factory()->create([
            'member_name' => 'resident2',
            'password' => 'Str0ng-pass!x',
            'position' => 4,
            'active' => true,
            'pass_exp_date' => now()->addYear()->format('Y-m-d'),
        ]);

        $this->post('/login', [
            'member_name' => 'resident2',
            'password' => 'Str0ng-pass!x',
            'remember' => true,
        ]);

        $this->assertAuthenticatedAs($user);
    }
}
