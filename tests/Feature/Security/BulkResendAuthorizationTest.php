<?php

namespace Tests\Feature\Security;

use App\Models\Invitation;
use App\Models\Person;
use App\Models\User;
use App\Support\Invitations\BulkResend;
use App\Support\Invitations\InvitationIssue;
use App\Support\PositionChange;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * F3 — **the bulk resend's whole gate was a `foreach` over a list that is routinely empty.**
 *
 * `bulkPreview()` and `bulkResend()` sit in an `auth`-only route group: the invitation endpoints
 * are this codebase's one deliberate exception to a `cap:` middleware, because the rule is
 * two-tier and position-dependent and is therefore applied in-controller by `ManagerScope`. That
 * exception is only sound if the in-controller pass actually asserts something. It did not.
 * `BulkResend::positionsToAuthorize()` returned the positions of LIVE INVITATIONS ONLY, so a
 * selection of people who have all claimed — or who were never invited — returned `[]`, the loop
 * asserted nothing, and any authenticated account received the plan: per person, their invitation
 * state, their invitation id, and whether they hold an account.
 *
 * The fix is a UNION: every selected person's `people.position` **and** their live invitations'
 * positions, so the list is never empty for a non-empty selection. `people.position` is also the
 * position the writer itself now authorizes against (F1), which is why one change closes both —
 * the pre-authorization pass and the writer are asking the same question of the same column.
 */
class BulkResendAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
        config(['mail.default' => 'smtp']);
    }

    private function admin(): User
    {
        return User::factory()->create(['position' => 0, 'full_name' => 'Aisha Admin']);
    }

    private function chief(): User
    {
        return User::factory()->create(['position' => 5, 'full_name' => 'Cara Chief']);
    }

    /** An ordinary authenticated member. Holds `rota.view` and nothing that manages anybody. */
    private function consultant(): User
    {
        return User::factory()->create(['position' => 3, 'full_name' => 'Kamal Consultant']);
    }

    private function requestAs(User $actor): Request
    {
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }

    /** @param  list<Person>  $people */
    private function ids(array $people): array
    {
        return array_map(static fn (Person $p): int => (int) $p->getKey(), $people);
    }

    /**
     * THE FINDING ITSELF. Every selected person has claimed, so there is not one live invitation
     * between them — and the old gate had nothing to iterate over.
     */
    public function test_an_authenticated_non_manager_is_refused_the_preview_of_a_fully_claimed_selection(): void
    {
        $this->admin();
        $claimed = [
            User::factory()->create(['position' => 4])->person,
            User::factory()->create(['position' => 4])->person,
        ];

        $this->actingAs($this->consultant())
            ->post('/admin/invitations/bulk-resend/preview', ['person_ids' => $this->ids($claimed)])
            ->assertForbidden()
            ->assertSessionMissing('invitation_bulk_preview');

        $this->assertDatabaseHas('audit_log', [
            'action' => 'access_denied',
            'detail' => 'cap=users.manage_residents',
        ]);
    }

    /** The same selection reaching the confirm, which writes and mails. */
    public function test_an_authenticated_non_manager_is_refused_the_confirm_of_a_fully_claimed_selection(): void
    {
        $claimed = [User::factory()->create(['position' => 4])->person];

        $this->actingAs($this->consultant())
            ->post('/admin/invitations/bulk-resend', [
                'person_ids' => $this->ids($claimed),
                'digest' => BulkResend::plan($this->ids($claimed))['digest'],
            ])
            ->assertForbidden();
    }

    /** The other empty-set shape: nobody in the selection was ever invited at all. */
    public function test_an_authenticated_non_manager_is_refused_a_selection_that_was_never_invited(): void
    {
        $never = [
            Person::factory()->create(['position' => 4]),
            Person::factory()->create(['position' => 3]),
        ];

        $this->assertDatabaseCount('invitations', 0);

        $this->actingAs($this->consultant())
            ->post('/admin/invitations/bulk-resend/preview', ['person_ids' => $this->ids($never)])
            ->assertForbidden();
    }

    /**
     * The property, stated directly rather than only through its consequences: a non-empty
     * selection always produces something to authorize.
     */
    public function test_positions_to_authorize_is_never_empty_for_a_non_empty_selection(): void
    {
        $claimed = User::factory()->create(['position' => 4])->person;
        $never = Person::factory()->create(['position' => 3]);

        $this->assertSame([4], BulkResend::positionsToAuthorize([(int) $claimed->getKey()]));
        $this->assertSame([3], BulkResend::positionsToAuthorize([(int) $never->getKey()]));
        $this->assertSame(
            [3, 4],
            BulkResend::positionsToAuthorize([(int) $claimed->getKey(), (int) $never->getKey()]),
        );

        // An empty selection is the one case that legitimately authorizes nothing — and it cannot
        // reach the endpoint anyway, `person_ids` being `required|array|min:1`.
        $this->assertSame([], BulkResend::positionsToAuthorize([]));
    }

    /**
     * F1's third leg, now closed at the PREVIEW too: the preview discloses this person's account
     * state, so it is refused on exactly the terms the confirm is.
     */
    public function test_a_chief_resident_is_refused_the_preview_of_a_person_who_outranks_the_row(): void
    {
        $admin = $this->admin();
        $person = Person::factory()->create(['position' => 4, 'email' => 'drifted.preview@example.test']);

        InvitationIssue::issue($this->requestAs($admin), $person, 4);
        PositionChange::apply($person, 3, $this->requestAs($admin));

        $this->actingAs($this->chief())
            ->post('/admin/invitations/bulk-resend/preview', ['person_ids' => [$person->getKey()]])
            ->assertForbidden();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'user_scope_denied',
            'detail' => 'target_position=3',
        ]);
    }

    /** No regression: a Chief Resident's own cohort still previews and still sends. */
    public function test_a_chief_resident_may_still_preview_and_resend_a_resident_cohort(): void
    {
        $admin = $this->admin();

        $people = [];

        foreach (['bulk.one@example.test', 'bulk.two@example.test'] as $email) {
            $person = Person::factory()->create(['position' => 4, 'email' => $email]);
            InvitationIssue::issue($this->requestAs($admin), $person, 4);
            $people[] = $person;
        }

        $chief = $this->chief();
        $ids = $this->ids($people);

        $this->actingAs($chief)
            ->post('/admin/invitations/bulk-resend/preview', ['person_ids' => $ids])
            ->assertRedirect()
            ->assertSessionHas('invitation_bulk_preview');

        $this->actingAs($chief)
            ->post('/admin/invitations/bulk-resend', [
                'person_ids' => $ids,
                'digest' => BulkResend::plan($ids)['digest'],
            ])
            ->assertRedirect()
            ->assertSessionHas('invitation_bulk_report');

        // Two fresh links, two superseded rows.
        $this->assertSame(4, Invitation::count());
    }

    /** An administrator is inside every tier and sees the plan for anybody. */
    public function test_an_administrator_may_preview_a_fully_claimed_selection(): void
    {
        $claimed = [User::factory()->create(['position' => 3])->person];

        $this->actingAs($this->admin())
            ->post('/admin/invitations/bulk-resend/preview', ['person_ids' => $this->ids($claimed)])
            ->assertRedirect()
            ->assertSessionHas('invitation_bulk_preview');
    }

    // --- F4: the pre-authorization pass must mirror the writer's supersede set ----------------

    /**
     * THE ADDRESS-CARRY-OVER FIXTURE.
     *
     * `InvitationIssue::liveFor()` matches `person_id = X OR member_email = Y`, because
     * `invitations.member_email` is frozen at send time while `people.email` can be corrected
     * afterwards (Decision G) — and both halves are load-bearing. `positionsToAuthorize()` matched
     * `whereIn('person_id', …)` and nothing else, so an invitation reachable only along the ADDRESS
     * axis was never pre-authorized. The writer's own `assertMayTarget()` then fired from INSIDE
     * `BulkResend::commit()`'s transaction, and the `user_scope_denied` row it wrote rolled back
     * with it: the refusal happened, and the trail did not record it (P1c-1 finding 12, reached
     * through a different door).
     *
     * THE SEQUENCE IS ORDINARY, and the order of its last two steps is what makes the state
     * reachable at all: `people.email` is UNIQUE, so two people can only be associated with one
     * address across time, and `issue()` supersedes by address, so the collision has to arise AFTER
     * both invitations exist. A Consultant is invited, moves mailbox, a Resident is invited at
     * their own address, and the Resident's address is then corrected onto the freed one — a
     * correction Decision G exists for.
     */
    public function test_an_invitation_reached_only_by_address_refuses_before_the_transaction(): void
    {
        $admin = $this->admin();

        // A Consultant is invited at 3, at an address they later move off. The invitation keeps the
        // address it was sent to — `member_email` is frozen at mint time.
        $consultant = Person::factory()->create(['position' => 3, 'email' => 'carried@example.test']);
        InvitationIssue::issue($this->requestAs($admin), $consultant, 3);
        $consultant->update(['email' => 'moved.on@example.test']);

        // A Resident is invited at 4, at their own address. Nothing collides yet.
        $resident = Person::factory()->create(['position' => 4, 'email' => 'resident.first@example.test']);
        InvitationIssue::issue($this->requestAs($admin), $resident, 4);

        // The Resident's address is then corrected onto the freed mailbox.
        $resident->update(['email' => 'carried@example.test']);

        $ids = [(int) $resident->getKey()];

        // The Consultant's link is still live, still addressed to `carried@example.test`, and a
        // resend for the Resident would supersede it — reached along the ADDRESS axis alone,
        // because its `person_id` names somebody else entirely.
        $this->actingAs($this->chief())
            ->post('/admin/invitations/bulk-resend', [
                'person_ids' => $ids,
                'digest' => BulkResend::plan($ids)['digest'],
            ])
            ->assertForbidden();

        // The refusal is IN THE TRAIL — which is the half that used to roll back.
        $this->assertDatabaseHas('audit_log', [
            'action' => 'user_scope_denied',
            'detail' => 'target_position=3',
        ]);

        // And nothing was written: no fresh row, and the Consultant's link survives untouched.
        $this->assertSame(2, Invitation::count());
        $this->assertNull(Invitation::where('position', 3)->value('revoked_at'));

        // The same fact, stated at the pass itself: the position it missed is 3.
        $this->assertSame([3, 4], BulkResend::positionsToAuthorize($ids));
    }

    /**
     * The divergence runs BOTH ways. `positionsToAuthorize()` had no expiry filter while the writer
     * has always had one, so an invitation that had merely aged out — a row the writer will not
     * touch, will not revoke and will not authorize — refused a batch the operator was entitled to.
     */
    public function test_an_expired_higher_position_row_does_not_refuse_the_batch(): void
    {
        $admin = $this->admin();

        $person = Person::factory()->create(['position' => 4, 'email' => 'aged.out@example.test']);

        // Invited at 3 once; that link ages out.
        InvitationIssue::issue($this->requestAs($admin), $person, 3);
        Invitation::query()->where('person_id', $person->getKey())
            ->update(['expires_at' => now()->subDay()]);

        // Then invited at 4. The expired row is not superseded — it expired, nobody killed it.
        InvitationIssue::issue($this->requestAs($admin), $person, 4);

        $this->assertNull(Invitation::where('position', 3)->value('revoked_at'));
        $this->assertSame([4], BulkResend::positionsToAuthorize([(int) $person->getKey()]));

        $ids = [(int) $person->getKey()];

        $this->actingAs($this->chief())
            ->post('/admin/invitations/bulk-resend', [
                'person_ids' => $ids,
                'digest' => BulkResend::plan($ids)['digest'],
            ])
            ->assertRedirect()
            ->assertSessionHas('invitation_bulk_report');
    }
}
