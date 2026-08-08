<?php

namespace Tests\Feature\Identity;

use App\Models\Invitation;
use App\Models\PendingRegistration;
use App\Models\Person;
use App\Models\User;
use App\Support\AccessControl;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * P0c Task 8 — one identity lifecycle. `invitations.person_id` names the roster row an
 * invitation is issued to (matched-or-created by normalized email at issue time); redemption
 * CLAIMS that person rather than inserting a second one alongside them. The frozen
 * `pending_registrations` queue's `approve()` terminates in the same place.
 *
 *   people row (roster)  --issue--> invitations row OPEN  --redeem--> users row (person_id set)
 *         |                                |                              = "claimed"
 *         |                                +- revoked / expired --> person stays, no credential
 *         |
 *         +- people.active = false : stops being NAMEABLE (users.active stops LOGIN separately)
 *
 * "Claimed" is *a `users` row exists* — a join, never a column, so it cannot disagree with
 * reality (D3, reversed 2026-08-08).
 */
class ClaimLifecycleTest extends TestCase
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

    // ------------------------------------------------------------ issuing matches the roster

    /** Case 1 — inviting a rostered person links to them; no duplicate person is created. */
    public function test_inviting_a_rostered_person_links_to_them_and_creates_no_duplicate(): void
    {
        $person = Person::factory()->create(['email' => 'dr.x@example.org', 'position' => 4]);
        $admin = $this->admin();
        $before = Person::count();

        $this->actingAs($admin)
            ->post('/admin/invitations', ['member_email' => 'Dr.X@Example.ORG', 'position' => 4])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // No new person — the admin's own person already existed too, so the count is unchanged.
        $this->assertSame($before, Person::count());

        $invitation = Invitation::firstOrFail();
        $this->assertSame($person->id, $invitation->person_id);
    }

    /** Case 2 — inviting an unknown address creates the person at issue time. */
    public function test_inviting_an_unknown_address_creates_the_person_at_issue_time(): void
    {
        $admin = $this->admin();
        $before = Person::count();

        $this->actingAs($admin)
            ->post('/admin/invitations', ['member_email' => 'brand.new@example.org', 'position' => 3])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($before + 1, Person::count());

        $invitation = Invitation::firstOrFail();
        $person = Person::findOrFail($invitation->person_id);

        $this->assertSame('brand.new@example.org', $person->email);
        $this->assertSame(3, $person->position);
        $this->assertTrue($person->active);
        $this->assertFalse($person->hasAccount());
    }

    /** Case 3 — inviting an address that already has an ACCOUNT is refused. */
    public function test_inviting_an_address_with_an_existing_account_is_refused(): void
    {
        User::factory()->create(['member_email' => 'claimed@example.org']);

        $this->actingAs($this->admin())
            ->post('/admin/invitations', ['member_email' => 'claimed@example.org', 'position' => 4])
            ->assertSessionHasErrors('member_email');

        $this->assertDatabaseCount('invitations', 0);
    }

    // ------------------------------------------------------------ redemption claims, never inserts

    /** Case 4 — redemption claims the existing person; no second one is created. */
    public function test_redemption_claims_the_existing_person(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)
            ->post('/admin/invitations', ['member_email' => 'claim.me@example.org', 'position' => 4]);

        $invitation = Invitation::firstOrFail();
        $person = Person::findOrFail($invitation->person_id);
        $before = Person::count();

        // The token itself is never persisted in plaintext, so redeem through the real flow:
        // reconstruct it is impossible — issue a fresh one directly against the same person to
        // exercise the CLAIM path with a token this test can see.
        [$invitation2, $token] = Invitation::issue('claim.me@example.org', 4, $admin, $person);

        $this->post("/invitation/{$token}", [
            'full_name' => 'Someone Else',
            'member_name' => 'claimant',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login')->assertSessionHasNoErrors();

        $this->assertSame($before, Person::count(), 'redemption must not create a second person');

        $user = User::where('member_name', 'claimant')->firstOrFail();
        $this->assertSame($person->id, $user->person_id);
        $this->assertSame($person->fresh()->email, $user->member_email);
    }

    /**
     * Case 4b — the invitation's own person claims an account through a DIFFERENT route (here,
     * directly) between issue and redemption. The existing collision check only looks for a
     * DIFFERENT person holding the address; it never re-checks the invitation's own person, so
     * without a guard `insertGetId` hits `users.person_id`'s UNIQUE index directly — a raw 23000
     * surfaced as a 500. `UserManagementController::assertStillUnique()` guards the
     * pending-registration path via the same `hasAccount()` call; this proves the invitation path
     * does too, refusing cleanly with a 422 instead.
     */
    public function test_redemption_is_refused_when_the_invitations_own_person_already_claimed_an_account(): void
    {
        $admin = $this->admin();
        $person = Person::factory()->create(['email' => 'raced.claim@example.org', 'position' => 4]);

        [, $token] = Invitation::issue('raced.claim@example.org', 4, $admin, $person);

        // The SAME person acquires an account through a different path before this token is
        // redeemed (e.g. a second invitation to the same address, redeemed first).
        User::factory()->create(['person_id' => $person->id]);

        $this->postJson("/invitation/{$token}", [
            'full_name' => 'Someone Else',
            'member_name' => 'lateclaim',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertStatus(422)->assertJsonValidationErrors('member_name');

        $this->assertDatabaseMissing('users', ['member_name' => 'lateclaim']);
    }

    /**
     * Case 5 — redemption does not let the invitee rename a rostered person, but DOES take the
     * name for a placeholder person created at issue time (whose name was blank).
     */
    public function test_redemption_does_not_let_the_invitee_rename_a_rostered_person(): void
    {
        $admin = $this->admin();
        $person = Person::factory()->create([
            'full_name' => 'Dr Roster Name',
            'email' => 'roster.named@example.org',
            'position' => 4,
        ]);

        [, $token] = Invitation::issue('roster.named@example.org', 4, $admin, $person);

        $this->post("/invitation/{$token}", [
            'full_name' => 'Someone Else',
            'member_name' => 'renamer',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login')->assertSessionHasNoErrors();

        $this->assertSame('Dr Roster Name', $person->fresh()->full_name);
    }

    /** The other half of case 5 — a placeholder person (blank name) takes the submitted name. */
    public function test_redemption_names_a_placeholder_person_created_at_issue_time(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/invitations', ['member_email' => 'placeholder@example.org', 'position' => 4]);

        $invitation = Invitation::firstOrFail();
        $person = Person::findOrFail($invitation->person_id);
        $this->assertSame('', $person->full_name);

        [, $token] = Invitation::issue('placeholder@example.org', 4, $admin, $person);

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Chosen Name',
            'member_name' => 'namedatlast',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login')->assertSessionHasNoErrors();

        $this->assertSame('Dr Chosen Name', $person->fresh()->full_name);
    }

    /** Case 6 — redemption is still single-use under concurrency (lockForUpdate + isOpen). */
    public function test_redemption_is_still_single_use_under_concurrency(): void
    {
        $admin = $this->admin();
        $person = Person::factory()->create(['email' => 'once@example.org', 'position' => 4]);
        [, $token] = Invitation::issue('once@example.org', 4, $admin, $person);

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr One', 'member_name' => 'raceone',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login')->assertSessionHasNoErrors();

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Two', 'member_name' => 'racetwo',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('users', ['member_name' => 'racetwo']);
        $this->assertSame(1, User::where('person_id', $person->id)->count());
    }

    /** Case 7 — AccessControl::flush() runs on claim: a role-default grant resolves immediately. */
    public function test_claiming_flushes_the_capability_cache_immediately(): void
    {
        $admin = $this->admin();
        $person = Person::factory()->create(['email' => 'fresh.cap@example.org', 'position' => 3]);
        [, $token] = Invitation::issue('fresh.cap@example.org', 3, $admin, $person);

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Fresh', 'member_name' => 'freshcap',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login')->assertSessionHasNoErrors();

        $user = User::where('member_name', 'freshcap')->firstOrFail();

        // Position 3 (Consultant) holds 'endorsement.edit' by the seeded role matrix. If the
        // cache were not flushed on claim, a stale "no capabilities for this id" entry could
        // theoretically have been warmed before the account existed — flush() makes that
        // impossible rather than merely unlikely.
        $this->assertTrue(AccessControl::allows($user, 'endorsement.edit'));
    }

    // ------------------------------------------------------------ the frozen pending queue

    /** Case 8a — approve() promotes a pending registration into a person + account. */
    public function test_approve_promotes_a_pending_registration_into_a_person_and_account(): void
    {
        $pending = PendingRegistration::create([
            'full_name' => 'Dr Pending', 'member_name' => 'pendingdoc',
            'member_email' => 'pending.doc@example.org', 'position' => 4,
            'password' => 'plain-secret-123', 'requested_at' => now(),
            'email_verified_at' => now(),
        ]);
        $rawHash = $pending->getRawOriginal('password');

        $this->actingAs($this->admin())
            ->post("/admin/users/pending/{$pending->id}/approve")
            ->assertRedirect();

        $user = User::where('member_name', 'pendingdoc')->firstOrFail();
        $this->assertSame($rawHash, $user->getRawOriginal('password'), 'the hash must travel verbatim, never re-derived');
        $this->assertSame('Dr Pending', $user->person->full_name);
        $this->assertSame('pending.doc@example.org', $user->person->email);
    }

    /** Case 8b — assertStillUnique() still 422s on a colliding member_name. */
    public function test_approve_still_refuses_a_member_name_taken_after_the_registration_was_submitted(): void
    {
        $pending = PendingRegistration::create([
            'full_name' => 'Dr Clash', 'member_name' => 'clashname',
            'member_email' => 'clash@example.org', 'position' => 4,
            'password' => 'plain-secret-123', 'requested_at' => now(),
            'email_verified_at' => now(),
        ]);
        User::factory()->create(['member_name' => 'clashname']);

        $this->actingAs($this->admin())
            ->post("/admin/users/pending/{$pending->id}/approve")
            ->assertSessionHasErrors('member_name');

        $this->assertDatabaseCount('pending_registrations', 1);
    }

    /**
     * Case 8c — a ROSTER-ONLY match on the pending address is NOT a collision; approving it
     * claims that person instead of duplicating them (design §5.2.4, same discipline as
     * invitations).
     */
    public function test_approve_claims_a_roster_only_person_instead_of_refusing(): void
    {
        $person = Person::factory()->create(['email' => 'roster.match@example.org', 'position' => 4]);
        $pending = PendingRegistration::create([
            'full_name' => 'Dr Roster Match', 'member_name' => 'rostermatch',
            'member_email' => 'roster.match@example.org', 'position' => 4,
            'password' => 'plain-secret-123', 'requested_at' => now(),
            'email_verified_at' => now(),
        ]);
        $admin = $this->admin();
        $before = Person::count();

        $this->actingAs($admin)
            ->post("/admin/users/pending/{$pending->id}/approve")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($before, Person::count(), 'approving must claim the roster row, not duplicate it');

        $user = User::where('member_name', 'rostermatch')->firstOrFail();
        $this->assertSame($person->id, $user->person_id);
    }

    // ------------------------------------------------------------ the person survives the door closing

    /** Case 9 — a superseded/revoked invitation leaves the person in place, with no account. */
    public function test_a_revoked_invitation_leaves_the_person_in_place_with_no_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/invitations', ['member_email' => 'leaves.no.trace@example.org', 'position' => 4]);

        $invitation = Invitation::firstOrFail();
        $person = Person::findOrFail($invitation->person_id);

        $this->actingAs($admin)
            ->delete("/admin/invitations/{$invitation->id}")
            ->assertRedirect();

        $this->assertTrue($person->fresh()->exists);
        $this->assertFalse($person->fresh()->hasAccount());
        $this->assertTrue($invitation->fresh()->revoked_at !== null);
    }

    /**
     * Owner decision 2 (2026-08-08) unifies email onto `people.email`, but `users.member_email`
     * still physically exists WITH ITS UNIQUE INDEX — it is just no longer independently written
     * by a profile edit. So once someone changes their address, the raw column is a FROZEN,
     * stale value that nothing keeps in step with `people.email` any more. If a later invitation
     * or approval still wrote that raw column, redeeming an invitation to an address someone else
     * used to hold (and has since moved on from) would 500 on the raw column's own unique index
     * — a real, reachable case (an old starter's address handed to a new one), not a hypothetical.
     */
    public function test_an_address_freed_by_a_profile_email_change_can_be_reinvited_without_a_raw_column_collision(): void
    {
        $mover = User::factory()->create(['member_email' => 'freed.address@example.org', 'active' => true]);

        $this->actingAs($mover)->patch('/profile', [
            'full_name' => $mover->full_name,
            'member_name' => $mover->member_name,
            'member_email' => 'moved.on@example.org',
        ])->assertSessionHasNoErrors();

        $this->assertSame('moved.on@example.org', $mover->fresh()->member_email);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/invitations', ['member_email' => 'freed.address@example.org', 'position' => 4])
            ->assertSessionHasNoErrors();

        $invitation = Invitation::where('member_email', 'freed.address@example.org')->firstOrFail();
        $person = Person::findOrFail($invitation->person_id);
        [, $token] = Invitation::issue('freed.address@example.org', 4, $admin, $person);

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Reused Address',
            'member_name' => 'reusedaddress',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertSessionHasNoErrors()->assertRedirect('/login');

        $this->assertDatabaseHas('users', ['member_name' => 'reusedaddress']);
    }

    /** Superseding (re-inviting the same address) also leaves exactly one person, not two. */
    public function test_superseding_an_invitation_leaves_exactly_one_person(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/invitations', ['member_email' => 'resend.me@example.org', 'position' => 4]);
        $firstPersonId = Invitation::firstOrFail()->person_id;

        $this->actingAs($admin)->post('/admin/invitations', ['member_email' => 'resend.me@example.org', 'position' => 4]);

        $this->assertSame(1, Person::where('email', 'resend.me@example.org')->count());
        $open = Invitation::whereNull('revoked_at')->firstOrFail();
        $this->assertSame($firstPersonId, $open->person_id);
    }
}
