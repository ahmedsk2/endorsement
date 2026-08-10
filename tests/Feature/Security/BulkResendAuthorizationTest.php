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
}
