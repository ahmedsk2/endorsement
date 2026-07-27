<?php

namespace Tests\Feature\Auth;

use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * A new account finishes setting itself up before it reaches the wards.
 *
 * Two questions this system must not answer on a clinician's behalf — how they prove who
 * they are at sign-in, and whether they want to be told about a handover. Both already had
 * controls, both on a profile page, and both skippable, so the realistic outcome was an
 * account that had chosen neither.
 *
 * The risk in a gate like this is not that it fails to gate. It is that it TRAPS someone —
 * a redirect loop, or a page whose own requests are gated. Most of what follows is about
 * that, because a clinician locked out of a handover system at 03:00 is a clinical problem,
 * not an inconvenience.
 */
class FirstLoginSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    private function fresh(int $position = 4): User
    {
        return User::factory()->create([
            'position' => $position,
            'active' => true,
            'email_verified_at' => now(),
            'setup_completed_at' => null,
        ]);
    }

    public function test_an_account_that_has_not_finished_setup_is_sent_to_it(): void
    {
        $this->actingAs($this->fresh())->get('/endorsement')->assertRedirect('/setup');
    }

    public function test_the_setup_page_renders_both_steps(): void
    {
        $this->actingAs($this->fresh())->get('/setup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Setup')
                ->where('security.active_method', null)
                ->has('reminders.units', 4));
    }

    public function test_a_finished_account_is_not_sent_back(): void
    {
        $user = User::factory()->create(['active' => true, 'setup_completed_at' => now()]);

        $this->actingAs($user)->get('/endorsement')->assertOk();
        // And the page itself refuses to reopen from a bookmark.
        $this->actingAs($user)->get('/setup')->assertRedirect('/endorsement');
    }

    // ---------------------------------------------------------------- not a trap

    public function test_nobody_can_be_locked_inside_their_own_setup(): void
    {
        $user = $this->fresh();

        // Signing out must always work, or an account stuck mid-setup has no way out at all.
        $this->actingAs($user)->post('/logout')->assertRedirect();
    }

    public function test_every_request_the_setup_page_makes_is_reachable_from_inside_it(): void
    {
        // The gate redirects everything except a short allowlist. If any of these were
        // gated, the page would redirect its own form posts to itself and the user could
        // never finish — the failure mode that makes a gate like this dangerous.
        $user = $this->fresh();

        $this->actingAs($user)->get('/user/two-factor')->assertOk();
        $this->actingAs($user)->patch('/profile/reminders', ['unit_ids' => []])->assertRedirect();
        $this->actingAs($user)->put('/profile/two-factor-method', ['method' => 'email'])->assertRedirect();
    }

    public function test_a_privileged_account_is_not_bounced_between_two_gates(): void
    {
        // EnforceTwoFactor sends an unconfigured privileged account to /profile, and this
        // gate sends it to /setup. With both live and neither exempting the other, an
        // administrator's first login would have ping-ponged forever.
        config(['endorsement.require_2fa' => true]);

        $admin = $this->fresh(0);

        $this->actingAs($admin)->get('/endorsement')->assertRedirect('/setup');
        $this->actingAs($admin)->get('/setup')->assertOk();
    }

    // ---------------------------------------------------------------- finishing

    public function test_setup_cannot_be_finished_without_a_second_factor(): void
    {
        $user = $this->fresh();

        $this->actingAs($user)->post('/setup')->assertSessionHasErrors('setup');

        $this->assertNull($user->fresh()->setup_completed_at);
    }

    public function test_enrolling_an_authenticator_satisfies_the_first_step(): void
    {
        $user = $this->fresh();

        $this->actingAs($user)->post('/user/two-factor');
        $user->refresh();
        $this->actingAs($user)->post('/user/two-factor/confirm', [
            'code' => (new Google2FA)->getCurrentOtp($user->two_factor_secret),
        ]);

        $this->actingAs($user->fresh())->post('/setup')->assertRedirect('/endorsement');

        $this->assertNotNull($user->fresh()->setup_completed_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'account_setup_completed']);
    }

    public function test_choosing_email_codes_also_satisfies_it(): void
    {
        $user = $this->fresh();

        $this->actingAs($user)->put('/profile/two-factor-method', ['method' => 'email'])->assertRedirect();
        $this->actingAs($user->fresh())->post('/setup')->assertRedirect('/endorsement');

        $this->assertNotNull($user->fresh()->setup_completed_at);
    }

    public function test_reminder_choices_made_during_setup_are_kept(): void
    {
        $user = $this->fresh();
        $picu = Unit::where('code', 'PICU')->firstOrFail();

        $this->actingAs($user)->patch('/profile/reminders', ['unit_ids' => [$picu->id]])->assertRedirect();

        $this->assertSame([$picu->id], $user->fresh()->reminderUnits()->pluck('units.id')->all());
    }

    public function test_an_invited_account_arrives_needing_setup(): void
    {
        // The link that makes this real rather than theoretical: invitations are now the
        // only way an account is created, so if that path set setup_completed_at, nobody
        // would ever see the flow. It inserts explicit columns, so the default is null —
        // asserted here so a future edit to that insert cannot silently switch this off.
        [, $token] = \App\Models\Invitation::issue('new@example.org', 4, $this->fresh(0));

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr New', 'member_name' => 'drnew',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login');

        $invited = User::where('member_name', 'drnew')->firstOrFail();

        $this->assertNull($invited->setup_completed_at);
        $this->actingAs($invited)->get('/endorsement')->assertRedirect('/setup');
    }

    public function test_the_gate_does_not_answer_an_api_request_with_a_redirect(): void
    {
        $this->actingAs($this->fresh())
            ->getJson('/endorsement')
            ->assertForbidden();
    }
}
