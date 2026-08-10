<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Person;
use App\Models\User;
use App\Support\AccessControl;
use App\Support\Invitations\BulkResend;
use App\Support\Invitations\InvitationIssue;
use App\Support\PositionChange;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * F1 — **the position an invitation is authorized against must be the position the account it
 * mints will actually hold.**
 *
 * Every invitation endpoint used to authorize against `invitations.position`:
 * `InvitationController::store()` (request-supplied), `::resend()` (the bound row's) and the two
 * bulk endpoints (via `BulkResend::positionsToAuthorize()`). But redemption
 * (`InvitationAcceptController::store()`) takes the `person_id !== null` branch for every
 * invitation this system now mints, and that branch **never writes `position`** — it is written
 * only on the legacy `person_id === null` branch, and deliberately so: `people.position` has ONE
 * writer (`App\Support\PositionChange`), and an invitee must not be able to re-rank the roster row
 * they are claiming. `$user->position` is a read-through accessor onto that roster row and
 * `AccessControl::resolve()` joins `role_capabilities` on it, so the minted account resolves its
 * capabilities from the **person**, not from the invitation — and the authorization check was
 * approving the wrong number.
 *
 * `InvitationStatus::mayInvite()` already gated the OFFER on `people.position`, which is D9's rule
 * (offer and write decide from one predicate) applied to a button. The write side did not, and
 * that disagreement is this whole finding.
 *
 * THE FIX IS AT THE ONE WRITER, beside the supersede loop and before the transaction opens, so all
 * three doors close together rather than three copies of a rule drifting — which is exactly what
 * `ManagerScope`'s own docblock records having cost once already.
 */
class InvitationPositionEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
        config(['mail.default' => 'array']);
    }

    private function admin(): User
    {
        return User::factory()->create(['position' => 0, 'full_name' => 'Aisha Admin']);
    }

    private function chief(): User
    {
        return User::factory()->create(['position' => 5, 'full_name' => 'Cara Chief']);
    }

    /** The `Request` the support-layer writers authorize and attribute against. */
    private function requestAs(User $actor): Request
    {
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }

    /**
     * LEG 4 — THE INNOCENT CHAIN. Not one step of this is a misuse of anything.
     *
     *  1. An administrator invites a new joiner as a Resident. Correct: `store()` opens the
     *     placeholder person at 4 and the invitation at 4.
     *  2. The administrator later corrects that roster row to Administrator on the People screen.
     *     Correct, and `PositionChange::apply()` does not (and must not) reach into `invitations`.
     *  3. The live invitation still reads 4.
     *  4. A Chief Resident resends it and the invitee redeems — and the account resolves
     *     `access.manage`, because capabilities come from the ROSTER row.
     */
    public function test_a_chief_resident_cannot_mint_an_administrator_through_a_stale_invitation(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/invitations', [
            'member_email' => 'new.joiner@example.test',
            'position' => 4,
        ])->assertRedirect();

        $person = Person::where('email', 'new.joiner@example.test')->firstOrFail();
        $invitation = Invitation::where('person_id', $person->getKey())->orderByDesc('id')->firstOrFail();

        // Step 2, through the one definition of a position change — which is what the People
        // screen calls.
        PositionChange::apply($person, 0, $this->requestAs($admin));

        // Step 3: the invitation was not rewritten, and must not be. It still reads 4.
        $this->assertSame(4, (int) $invitation->fresh()->position);
        $this->assertSame(0, (int) $person->fresh()->position);

        // Step 4 is where it has to stop.
        $this->actingAs($this->chief())
            ->post('/admin/invitations/'.$invitation->getKey().'/resend')
            ->assertForbidden();

        // Nothing was minted and nothing was superseded: the chief holds no link at all.
        $this->assertSame(1, Invitation::where('person_id', $person->getKey())->count());
        $this->assertNull($invitation->fresh()->revoked_at);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'user_scope_denied',
            'detail' => 'target_position=0',
        ]);
    }

    /**
     * WHY THE PERSON'S POSITION IS THE ONE THAT MATTERS — the mechanism the check above is
     * defending, stated once so a future reader does not have to infer it.
     *
     * Redeemed by an administrator's own invitation, so nothing here is refused; the point is the
     * capability set the account comes out holding.
     */
    public function test_a_redeemed_account_resolves_capabilities_from_the_roster_not_the_invitation(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/invitations', [
            'member_email' => 'joiner.two@example.test',
            'position' => 4,
        ])->assertRedirect();

        $person = Person::where('email', 'joiner.two@example.test')->firstOrFail();
        PositionChange::apply($person, 0, $this->requestAs($admin));

        $link = session('invitation_link');
        $token = (string) last(explode('/', (string) $link));

        $this->post('/invitation/'.$token, [
            'full_name' => 'New Joiner',
            'member_name' => 'newjoiner',
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login');

        $user = User::where('member_name', 'newjoiner')->firstOrFail();

        // The invitation said 4. The account holds the Administrator's capability, because the
        // ROSTER row says 0 and that is what `AccessControl::resolve()` reads.
        $this->assertSame(4, (int) Invitation::where('person_id', $person->getKey())->value('position'));
        $this->assertSame(0, (int) $user->position);
        $this->assertTrue(AccessControl::allows($user, 'access.manage'));
    }

    /**
     * LEG 1 — the invite path, reachable directly with no stale row anywhere: a Chief Resident
     * invites an existing roster-only Administrator "as a Resident". `store()`'s own
     * `assertMayTarget()` reads the REQUEST's position (4, which a chief may target) and then
     * `Person::matchByEmail()` binds the invitation to a person at 0.
     *
     * This leg is pre-existing on `main`; the two below are doors P1c-2 opened.
     */
    public function test_a_chief_resident_cannot_invite_an_administrator_as_a_resident(): void
    {
        Person::factory()->create(['position' => 0, 'email' => 'roster.admin@example.test']);

        $this->actingAs($this->chief())->post('/admin/invitations', [
            'member_email' => 'roster.admin@example.test',
            'position' => 4,
        ])->assertForbidden();

        $this->assertDatabaseCount('invitations', 0);
    }

    /** LEG 2 — resend, where the bound person outranks the row. */
    public function test_a_chief_resident_cannot_resend_an_invitation_bound_to_a_consultant(): void
    {
        $admin = $this->admin();
        $person = Person::factory()->create(['position' => 4, 'email' => 'drifted@example.test']);

        InvitationIssue::issue($this->requestAs($admin), $person, 4);
        $invitation = Invitation::where('person_id', $person->getKey())->orderByDesc('id')->firstOrFail();

        PositionChange::apply($person, 3, $this->requestAs($admin));

        $this->actingAs($this->chief())
            ->post('/admin/invitations/'.$invitation->getKey().'/resend')
            ->assertForbidden();

        $this->assertSame(1, Invitation::where('person_id', $person->getKey())->count());
    }

    /**
     * LEG 3 — the same target, reached through the bulk CONFIRM.
     *
     * The digest is taken from `BulkResend::plan()` rather than from a preview request, because a
     * wrong digest is refused as a stale plan (a 422) BEFORE the writer is ever reached, and this
     * case is about the writer. F3 is what refuses the chief at the preview as well.
     */
    public function test_a_chief_resident_cannot_bulk_resend_onto_a_person_who_outranks_the_row(): void
    {
        config(['mail.default' => 'smtp']);

        $admin = $this->admin();
        $person = Person::factory()->create(['position' => 4, 'email' => 'drifted.bulk@example.test']);

        InvitationIssue::issue($this->requestAs($admin), $person, 4);
        PositionChange::apply($person, 3, $this->requestAs($admin));

        $this->actingAs($this->chief())->post('/admin/invitations/bulk-resend', [
            'person_ids' => [$person->getKey()],
            'digest' => BulkResend::plan([(int) $person->getKey()])['digest'],
        ])->assertForbidden();

        $this->assertSame(1, Invitation::where('person_id', $person->getKey())->count());
    }

    /**
     * The other half of the rule: an administrator may still do every one of the above, and a
     * Chief Resident's ordinary work is untouched.
     *
     * `store()`'s create branch opens the person at exactly `$position`, so the new assertion is a
     * no-op there — which is the property that lets one check cover three endpoints without
     * narrowing any of them.
     */
    public function test_a_chief_resident_may_still_invite_and_resend_a_resident(): void
    {
        $chief = $this->chief();

        $this->actingAs($chief)->post('/admin/invitations', [
            'member_email' => 'ordinary.resident@example.test',
            'position' => 4,
        ])->assertRedirect()->assertSessionHas('invitation_link');

        $person = Person::where('email', 'ordinary.resident@example.test')->firstOrFail();
        $this->assertSame(4, (int) $person->position);

        $invitation = Invitation::where('person_id', $person->getKey())->orderByDesc('id')->firstOrFail();

        $this->actingAs($chief)
            ->post('/admin/invitations/'.$invitation->getKey().'/resend')
            ->assertRedirect()
            ->assertSessionHas('invitation_link');

        // Rotated, and the first one killed — the resend behaved exactly as it always has.
        $this->assertSame(2, Invitation::where('person_id', $person->getKey())->count());
        $this->assertNotNull($invitation->fresh()->revoked_at);
    }

    /** An administrator is inside every tier, so none of the four legs refuses them. */
    public function test_an_administrator_may_resend_onto_a_person_who_outranks_the_row(): void
    {
        $admin = $this->admin();
        $person = Person::factory()->create(['position' => 4, 'email' => 'admin.path@example.test']);

        InvitationIssue::issue($this->requestAs($admin), $person, 4);
        $invitation = Invitation::where('person_id', $person->getKey())->orderByDesc('id')->firstOrFail();

        PositionChange::apply($person, 0, $this->requestAs($admin));

        $this->actingAs($admin)
            ->post('/admin/invitations/'.$invitation->getKey().'/resend')
            ->assertRedirect()
            ->assertSessionHas('invitation_link');

        $this->assertSame(2, Invitation::where('person_id', $person->getKey())->count());
    }

    /** No refusal is silent: every one of them leaves the tier refusal in the trail. */
    public function test_the_refusal_names_the_attempted_position_in_the_trail(): void
    {
        $admin = $this->admin();
        $person = Person::factory()->create(['position' => 4, 'email' => 'trail@example.test']);

        InvitationIssue::issue($this->requestAs($admin), $person, 4);
        $invitation = Invitation::where('person_id', $person->getKey())->orderByDesc('id')->firstOrFail();

        PositionChange::apply($person, 3, $this->requestAs($admin));

        $chief = $this->chief();

        $this->actingAs($chief)
            ->post('/admin/invitations/'.$invitation->getKey().'/resend')
            ->assertForbidden();

        $row = AuditLog::where('action', 'user_scope_denied')->orderByDesc('id')->firstOrFail();

        $this->assertSame('target_position=3', $row->detail);
        $this->assertSame((int) $chief->getKey(), (int) $row->user_id);
    }
}
