<?php

namespace Tests\Feature;

use App\Models\PendingRegistration;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * F1 — own-profile editing (re-platform of legacy profile.php). `auth` + `cap:profile.manage`
 * (every seeded role). The update binds to the SESSION identity only — any submitted id is
 * ignored (IDOR-safe, exactly like the legacy page). member_name / member_email are
 * uniqueness-checked against `users` (excluding self) AND `pending_registrations`; the write
 * is audited PHI-free (id only).
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_a_user_can_view_their_own_profile(): void
    {
        $user = User::factory()->create([
            'position' => 4,
            'full_name' => 'Own Name',
            'member_name' => 'own.name',
            'member_email' => 'own@example.com',
        ]);

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Edit')
                ->where('profile.full_name', 'Own Name')
                ->where('profile.member_name', 'own.name')
                ->where('profile.member_email', 'own@example.com')
            );
    }

    public function test_the_profile_requires_authentication(): void
    {
        $this->get('/profile')->assertRedirect('/login');
    }

    public function test_a_user_can_update_their_own_profile_and_the_change_is_audited(): void
    {
        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($user)
            ->patch('/profile', [
                'full_name' => 'New Full Name',
                'member_name' => 'new.member.name',
                'member_email' => 'new@example.com',
            ])
            ->assertRedirect();

        $fresh = $user->fresh();
        $this->assertSame('New Full Name', $fresh->full_name);
        $this->assertSame('new.member.name', $fresh->member_name);
        $this->assertSame('new@example.com', $fresh->member_email);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'profile_update',
            'user_id' => $user->id,
            'detail' => 'user='.$user->id,
        ]);
    }

    public function test_a_submitted_foreign_id_is_ignored_and_only_the_session_user_changes(): void
    {
        $user = User::factory()->create(['position' => 4]);
        $victim = User::factory()->create([
            'full_name' => 'Victim Name',
            'member_name' => 'victim.name',
            'member_email' => 'victim@example.com',
        ]);

        // IDOR probe: smuggle the victim's id under every plausible key.
        $this->actingAs($user)
            ->patch('/profile', [
                'id' => $victim->id,
                'user_id' => $victim->id,
                'member_id' => $victim->id,
                'full_name' => 'Attacker Chosen',
                'member_name' => 'attacker.chosen',
                'member_email' => 'attacker@example.com',
            ])
            ->assertRedirect();

        // The victim row is untouched; the session user's own row changed.
        $freshVictim = $victim->fresh();
        $this->assertSame('Victim Name', $freshVictim->full_name);
        $this->assertSame('victim.name', $freshVictim->member_name);
        $this->assertSame('victim@example.com', $freshVictim->member_email);

        $this->assertSame('attacker.chosen', $user->fresh()->member_name);
    }

    public function test_member_name_must_be_unique_across_other_users(): void
    {
        $user = User::factory()->create(['position' => 4]);
        User::factory()->create(['member_name' => 'taken.name']);

        $this->actingAs($user)
            ->patchJson('/profile', [
                'full_name' => 'Whatever',
                'member_name' => 'taken.name',
                'member_email' => 'free@example.com',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('member_name');
    }

    public function test_member_email_must_be_unique_across_other_users(): void
    {
        $user = User::factory()->create(['position' => 4]);
        User::factory()->create(['member_email' => 'taken@example.com']);

        $this->actingAs($user)
            ->patchJson('/profile', [
                'full_name' => 'Whatever',
                'member_name' => 'free.name',
                'member_email' => 'taken@example.com',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('member_email');
    }

    public function test_member_name_must_not_collide_with_the_pending_queue(): void
    {
        $user = User::factory()->create(['position' => 4]);
        PendingRegistration::create([
            'full_name' => 'Pending Person',
            'member_name' => 'pending.taken',
            'member_email' => 'pending.taken@example.com',
            'position' => 4,
            'password' => 'irrelevant-plain',
            'requested_at' => now(),
        ]);

        $this->actingAs($user)
            ->patchJson('/profile', [
                'full_name' => 'Whatever',
                'member_name' => 'pending.taken',
                'member_email' => 'free@example.com',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('member_name');
    }

    public function test_keeping_your_own_current_name_and_email_is_allowed(): void
    {
        $user = User::factory()->create([
            'position' => 4,
            'member_name' => 'same.name',
            'member_email' => 'same@example.com',
        ]);

        // Re-submitting your own current identifiers must not trip the unique rules.
        $this->actingAs($user)
            ->patch('/profile', [
                'full_name' => 'Renamed Only',
                'member_name' => 'same.name',
                'member_email' => 'same@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Only', $user->fresh()->full_name);
    }

    public function test_changing_a_password_has_its_own_page(): void
    {
        // It used to be the third stacked form on /profile, each with its own Save button.
        $user = User::factory()->create(['active' => true]);

        $this->actingAs($user)->get('/profile/password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Profile/Password'));
    }

    public function test_the_voluntary_and_the_forced_password_changes_stay_separate(): void
    {
        // These two look alike and must never be merged. /profile/password requires a live
        // session AND the current password. password.change exists precisely because its
        // user is NOT authenticated — their password expired, so login was never completed.
        // Collapsing them would either lock out the expired user or let an unauthenticated
        // request change a password.
        $this->get('/profile/password')->assertRedirect('/login');

        $user = User::factory()->create([
            'active' => true,
            'pass_exp_date' => now()->subYear()->format('Y-m-d'),
        ]);

        $this->post('/login', ['member_name' => $user->member_name, 'password' => 'password'])
            ->assertRedirect(route('password.change'));

        // And the forced flow is reachable without a session, by design.
        $this->get(route('password.change'))->assertOk();
    }
}
