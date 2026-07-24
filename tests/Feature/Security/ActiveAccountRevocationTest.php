<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * G6-S2 — deactivating an account must revoke the LIVE session, not just block the next login.
 * The legacy app re-checks `active = 1` on every request (require_auth.php); the Laravel port
 * lost that, so a departing/suspended staff member kept full PHI access until they logged out.
 * `EnsureAccountActive` restores the per-request re-check.
 */
class ActiveAccountRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    public function test_deactivating_an_account_out_of_band_logs_the_live_session_out(): void
    {
        // A REAL login: the session then holds only the user id, so every subsequent request
        // re-reads the account from the database — the exact production path this control guards.
        $user = User::factory()->create([
            'position' => 0,
            'member_name' => 'dr_leaving',
            'password' => 'secret-pass1',
            'active' => true,
        ]);

        $this->post('/login', ['member_name' => 'dr_leaving', 'password' => 'secret-pass1']);
        $this->assertAuthenticatedAs($user);
        $this->get('/profile')->assertOk();

        // Out-of-band deactivation (admin console / DBA) — the session store is not touched.
        DB::table('users')->where('id', $user->getKey())->update(['active' => 0]);

        // In production every request is a fresh process, so the guard re-reads the account from
        // the database. The test harness keeps one container across requests, so the resolved
        // guard is dropped here to reproduce that — without this the assertion would pass against
        // a stale in-memory User and prove nothing.
        $this->app['auth']->forgetGuards();

        $this->get('/profile')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_deactivated_account_cannot_reach_a_write_endpoint(): void
    {
        $user = User::factory()->create(['position' => 0, 'active' => false]);

        $this->actingAs($user)->patch('/profile', ['full_name' => 'X'])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_an_active_account_is_unaffected(): void
    {
        $user = User::factory()->create(['position' => 0, 'active' => true]);

        $this->actingAs($user)->get('/profile')->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}
