<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Accounts are created by invitation only (owner decision, 2026-07-27).
 *
 * `/register` was an unauthenticated endpoint on the public internet that wrote to the
 * database and mailed a stranger on their own say-so. The approval behind it was a real
 * control — and it is the control docs/COMPLIANCE.md leans on to justify every clinical
 * account seeing all four units — so it was made stronger rather than merely guarded.
 *
 * The properties that matter, each pinned below: the role travels with the INVITATION and
 * never with the form; the address is fixed; the link works once, expires, and can be
 * revoked; and failure tells a stranger nothing about which of those it was.
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['position' => 0]);
    }

    private function chief(): User
    {
        return User::factory()->create(['position' => 5]);
    }

    /** Issue directly, for the redemption tests. */
    private function invite(int $position = 4, string $email = 'new.doctor@example.org'): array
    {
        return Invitation::issue($email, $position, $this->admin());
    }

    // ------------------------------------------------------------------ the closed door

    public function test_self_registration_is_closed(): void
    {
        $this->get('/register')->assertRedirect('/login');

        // And the POST is gone entirely rather than merely redirecting.
        $this->post('/register', [
            'full_name' => 'Someone', 'member_name' => 'someone',
            'member_email' => 'someone@example.org', 'position' => 4,
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertStatus(405);

        $this->assertDatabaseCount('users', 0);
    }

    // ------------------------------------------------------------------ issuing

    public function test_an_administrator_can_invite(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/invitations', ['member_email' => 'New.Doctor@Example.org', 'position' => 4])
            ->assertRedirect();

        $invitation = Invitation::firstOrFail();

        // Normalised, so a second invitation to the same person in different case is caught.
        $this->assertSame('new.doctor@example.org', $invitation->member_email);
        $this->assertSame(4, $invitation->position);
        $this->assertTrue($invitation->isOpen());
        $this->assertDatabaseHas('audit_log', ['action' => 'invitation_issued']);
    }

    public function test_the_token_is_never_stored_in_plaintext(): void
    {
        [$invitation, $token] = $this->invite();

        // The stored value must be a hash of the token, not the token.
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);

        // And nothing anywhere in the row carries it — a database read must not yield a
        // working credential that creates an account.
        $row = json_encode(\Illuminate\Support\Facades\DB::table('invitations')->first());
        $this->assertStringNotContainsString($token, (string) $row);

        // Nor does the audit trail.
        $details = \App\Models\AuditLog::pluck('detail')->implode(' ');
        $this->assertStringNotContainsString($token, $details);
    }

    public function test_a_chief_resident_may_invite_residents_only(): void
    {
        $chief = $this->chief();

        $this->actingAs($chief)
            ->post('/admin/invitations', ['member_email' => 'a@example.org', 'position' => 4])
            ->assertRedirect();

        // A Consultant is outside their tier — the same rule that governs approvals.
        $this->actingAs($chief)
            ->post('/admin/invitations', ['member_email' => 'b@example.org', 'position' => 3])
            ->assertForbidden();

        $this->assertDatabaseCount('invitations', 1);
        $this->assertDatabaseHas('audit_log', ['action' => 'user_scope_denied']);
    }

    public function test_a_clinician_cannot_invite_anyone(): void
    {
        $this->actingAs(User::factory()->create(['position' => 4]))
            ->post('/admin/invitations', ['member_email' => 'a@example.org', 'position' => 4])
            ->assertForbidden();

        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_privileged_roles_can_never_be_invited_into(): void
    {
        // 0 Administrator and 5 Chief Resident are promotions applied to an existing
        // account, never a role anyone is created into; 1 Nurse is retired.
        foreach ([0, 1, 5, 9] as $position) {
            $this->actingAs($this->admin())
                ->post('/admin/invitations', ['member_email' => "p{$position}@example.org", 'position' => $position])
                ->assertSessionHasErrors('position');
        }

        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_inviting_the_same_address_again_revokes_the_previous_link(): void
    {
        // Otherwise "resend" multiplies live credentials for one person, and revoking the
        // one you can see leaves the others working.
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/invitations', ['member_email' => 'a@example.org', 'position' => 4]);
        $this->actingAs($admin)->post('/admin/invitations', ['member_email' => 'a@example.org', 'position' => 4]);

        $this->assertSame(1, Invitation::whereNull('revoked_at')->count());
        $this->assertSame(2, Invitation::count());
    }

    public function test_superseding_an_invitation_outside_your_tier_is_refused(): void
    {
        // Re-inviting an address revokes the open invitation for it. That revoke was a
        // blanket update with no authorisation and no audit entry, so a Chief Resident
        // inviting an address that already held a CONSULTANT invitation would cancel it —
        // an action they cannot perform through the revoke route itself.
        $this->actingAs($this->admin())
            ->post('/admin/invitations', ['member_email' => 'shared@example.org', 'position' => 3]);

        $this->actingAs($this->chief())
            ->post('/admin/invitations', ['member_email' => 'shared@example.org', 'position' => 4])
            ->assertForbidden();

        // The consultant's invitation is untouched, and no second one was minted.
        $this->assertSame(1, Invitation::whereNull('revoked_at')->count());
        $this->assertSame(3, (int) Invitation::firstOrFail()->position);
    }

    public function test_superseding_an_invitation_is_audited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/invitations', ['member_email' => 'a@example.org', 'position' => 4]);
        $this->actingAs($admin)->post('/admin/invitations', ['member_email' => 'a@example.org', 'position' => 4]);

        $detail = (string) \App\Models\AuditLog::where('action', 'invitation_revoked')
            ->orderByDesc('id')->value('detail');

        $this->assertStringContainsString('reason=superseded', $detail);
    }

    public function test_an_existing_account_cannot_be_invited(): void
    {
        User::factory()->create(['member_email' => 'taken@example.org']);

        $this->actingAs($this->admin())
            ->post('/admin/invitations', ['member_email' => 'taken@example.org', 'position' => 4])
            ->assertSessionHasErrors('member_email');
    }

    // ------------------------------------------------------------------ how long it lives

    /*
     * AC-02: invitations expire, and the window is a department decision rather than a
     * constant. Seven days is a DELIBERATE override of Munawib AC-02's fourteen (P1 owner
     * decision 5, round 2) — a redeemed link reaches a child's clinical records, so a
     * forwarded one stays live for half as long.
     */

    public function test_an_invitation_expires_after_the_configured_number_of_days(): void
    {
        \App\Support\AppSettings::set('invitation_lifetime_days', '14');

        [$invitation] = $this->invite();

        $this->assertSame(14, Invitation::lifetimeDays());
        $this->assertSame(
            now()->addDays(14)->format('Y-m-d H:i'),
            $invitation->expires_at->format('Y-m-d H:i'),
        );
    }

    public function test_an_unset_or_absurd_setting_falls_back_to_seven(): void
    {
        // Nothing configured at all: the deployed default applies untouched.
        $this->assertSame(7, Invitation::lifetimeDays());

        // And a value written straight into the table — a database-console edit the
        // FormRequest never saw, which is the ONLY reason the clamp exists.
        \App\Models\AppSetting::updateOrCreate(
            ['key' => 'invitation_lifetime_days'],
            ['value' => '999'],
        );
        \Illuminate\Support\Facades\Cache::flush();

        $this->assertSame(7, Invitation::lifetimeDays());

        [$invitation] = $this->invite();
        $this->assertSame(
            now()->addDays(7)->format('Y-m-d H:i'),
            $invitation->expires_at->format('Y-m-d H:i'),
        );
    }

    public function test_the_default_is_seven_and_the_constant_is_the_default_not_the_value(): void
    {
        $this->assertSame(7, Invitation::LIFETIME_DAYS);
        $this->assertSame(1, Invitation::LIFETIME_MIN);
        $this->assertSame(30, Invitation::LIFETIME_MAX);

        // With nothing stored, the constant IS what is returned — but through the method,
        // which is the one definition every caller must read.
        $this->assertSame(Invitation::LIFETIME_DAYS, Invitation::lifetimeDays());

        // A configured value inside the bounds wins over the constant.
        \App\Support\AppSettings::set('invitation_lifetime_days', '1');
        $this->assertSame(1, Invitation::lifetimeDays());
    }

    // ------------------------------------------------------------------ what the row means

    /*
     * P1c-2 Decision G. `invitations.position` is an AUTHORIZATION SUBJECT — what `ManagerScope`
     * was asked to approve when the link was minted — and not a role assignment. The claim path
     * already reads it that way (finding 17: it is taken only on the branch that CREATES a person,
     * commented in place as "FROM THE INVITATION, never from the request"), but nothing asserted
     * it, so a refactor could have turned a stale link into a demotion with no test going red.
     */

    public function test_claiming_an_invitation_never_changes_an_existing_roster_persons_position(): void
    {
        $person = \App\Models\Person::create([
            'full_name' => 'Dr Established', 'position' => 5, 'email' => 'chief@example.org', 'active' => true,
        ]);

        // Issued at 4 — a lower role than the roster records. The link is what expires; the
        // roster is the record.
        [, $token] = Invitation::issue('chief@example.org', 4, $this->admin(), $person);

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Renamed',
            'member_name' => 'established',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login');

        $person->refresh();

        $this->assertSame(5, (int) $person->position, 'A stale invitation must not demote a roster person.');
        // And the roster stays the name of record: an invitee cannot rename themselves onto a
        // signed sheet.
        $this->assertSame('Dr Established', $person->full_name);
    }

    public function test_the_offerable_positions_are_unchanged(): void
    {
        // Finding 13, pinned because P1c-2 adds a SECOND door onto the same mint. Widening this
        // list would make an invitation a route to privilege: 0 (Administrator) is never granted
        // this way, 1 (Nurse) is retired, and 5 (Chief Resident) is a promotion applied to an
        // existing account rather than a role anyone is created into.
        $this->assertSame(
            [2, 3, 4],
            array_keys(\App\Http\Controllers\Admin\InvitationController::OFFERABLE),
        );
    }

    // ------------------------------------------------------------------ redeeming

    public function test_accepting_an_invitation_creates_an_active_verified_account(): void
    {
        [$invitation, $token] = $this->invite(3, 'consultant@example.org');

        $this->get("/invitation/{$token}")->assertOk();

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr New',
            'member_name' => 'drnew',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login');

        $user = User::where('member_name', 'drnew')->firstOrFail();

        // Address and role come from the INVITATION.
        $this->assertSame('consultant@example.org', $user->member_email);
        $this->assertSame(3, $user->position);
        $this->assertTrue((bool) $user->active);
        // Redeeming a link delivered to that address IS the proof of the address.
        $this->assertNotNull($user->email_verified_at);
        // The 90-day rotation is armed; left null it never fires at all.
        $this->assertNotNull($user->pass_exp_date);
        $this->assertTrue(Hash::check('Str0ng!Passw0rd', $user->password));

        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'invitation_accepted']);
    }

    public function test_the_invitee_cannot_choose_their_own_role_or_address(): void
    {
        // The whole point. Even if the form is tampered with, neither value is read from it.
        [, $token] = $this->invite(4, 'resident@example.org');

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Sneaky',
            'member_name' => 'sneaky',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
            'position' => 0,
            'member_email' => 'attacker@example.org',
        ])->assertRedirect('/login');

        $user = User::where('member_name', 'sneaky')->firstOrFail();

        $this->assertSame(4, $user->position);
        $this->assertSame('resident@example.org', $user->member_email);
    }

    public function test_an_invitation_works_exactly_once(): void
    {
        [, $token] = $this->invite();

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr One', 'member_name' => 'one', 'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login');

        $this->get("/invitation/{$token}")->assertRedirect('/login');

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Two', 'member_name' => 'two', 'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('users', ['member_name' => 'two']);
    }

    public function test_an_expired_invitation_is_refused(): void
    {
        [$invitation, $token] = $this->invite();
        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get("/invitation/{$token}")->assertRedirect('/login');
        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Late', 'member_name' => 'late', 'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('users', ['member_name' => 'late']);
    }

    public function test_a_revoked_invitation_is_refused(): void
    {
        [$invitation, $token] = $this->invite();

        $this->actingAs($this->admin())
            ->delete("/admin/invitations/{$invitation->id}")
            ->assertRedirect();

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Gone', 'member_name' => 'gone', 'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('users', ['member_name' => 'gone']);
        $this->assertDatabaseHas('audit_log', ['action' => 'invitation_revoked']);
    }

    public function test_a_forged_or_unknown_token_is_refused_without_saying_why(): void
    {
        [, $real] = $this->invite();

        // The near-miss must differ from the real token EVERY time. Appending a fixed '0' to
        // the first 63 characters reproduces the original whenever it happens to end in '0'
        // — a one-in-sixteen flake that duly failed a run.
        $nearMiss = substr($real, 0, 63).(str_ends_with($real, '0') ? '1' : '0');

        // A well-formed-but-wrong token, a malformed one, and a near-miss of a real token.
        foreach ([str_repeat('a', 64), 'not-a-token', $nearMiss] as $bad) {
            $this->get('/invitation/'.$bad)->assertRedirect('/login');
        }

        // Path traversal never even reaches the controller — {token} does not match a slash.
        $this->get('/invitation/'.urlencode('../../etc/passwd'))->assertNotFound();

        // Every refusal reads the same, so this is not an oracle for valid addresses.
        $this->assertDatabaseCount('users', 1);   // only the admin from invite()
    }

    public function test_the_password_policy_applies_to_an_invited_account(): void
    {
        [, $token] = $this->invite();

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Weak', 'member_name' => 'weak',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['member_name' => 'weak']);
        // And a failed attempt must not burn the invitation.
        $this->assertTrue(Invitation::firstOrFail()->isOpen());
    }

    public function test_a_username_already_taken_is_refused(): void
    {
        User::factory()->create(['member_name' => 'taken']);
        [, $token] = $this->invite();

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Clash', 'member_name' => 'taken', 'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertSessionHasErrors('member_name');

        $this->assertTrue(Invitation::firstOrFail()->isOpen());
    }

    /*
     * The three assertions below were carried over from RegistrationTest, which was deleted
     * with the endpoint it exercised. They are unchanged in substance — the same collisions
     * and the same confirmation rule, at the door that is now open instead.
     */

    public function test_a_username_queued_in_the_pending_registrations_is_refused(): void
    {
        // The pending queue still holds anyone registered before invitations existed, and
        // an administrator can still approve them, so its identifiers are still taken.
        \App\Models\PendingRegistration::create([
            'full_name' => 'Queued Person', 'member_name' => 'queued',
            'member_email' => 'queued@example.org', 'position' => 4,
            'password' => 'Secret-pass1', 'requested_at' => now(),
        ]);

        [, $token] = $this->invite();

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Clash', 'member_name' => 'queued',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertSessionHasErrors('member_name');
    }

    public function test_an_address_queued_in_the_pending_registrations_cannot_be_invited(): void
    {
        \App\Models\PendingRegistration::create([
            'full_name' => 'Queued Person', 'member_name' => 'queued2',
            'member_email' => 'queued2@example.org', 'position' => 4,
            'password' => 'Secret-pass1', 'requested_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->post('/admin/invitations', ['member_email' => 'queued2@example.org', 'position' => 4])
            ->assertSessionHasErrors('member_email');
    }

    public function test_the_password_must_be_confirmed(): void
    {
        [, $token] = $this->invite();

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Typo', 'member_name' => 'typo',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rdX',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['member_name' => 'typo']);
    }
}
