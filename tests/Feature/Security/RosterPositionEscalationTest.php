<?php

namespace Tests\Feature\Security;

use App\Models\Capability;
use App\Models\Person;
use App\Models\User;
use App\Models\UserCapability;
use App\Support\AccessControl;
use App\Support\PositionChange;
use App\Support\Roster\CsvRosterReader;
use App\Support\Roster\RosterImport;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * F2 — **`people.manage` was already a path to `access.manage`, and the roles panel is not what
 * made it one.**
 *
 * P1c-2 Decision F gated the People screen's per-person roles panel on `access.manage` rather than
 * on the route's own `people.manage`, reasoning that hanging a role control off the roster gate
 * would create a privilege-escalation path. The reasoning is right and the gate is right, but the
 * stated premise is false: the path already existed one field to the left. `PersonRequest::
 * POSITIONS` offers 0, position 0 holds `access.manage` by seeded role default, and
 * `AccessControl::resolve()` reads `people.position` — so a `people.manage` holder promotes their
 * own roster row, waits for the 600-second capability cache to be flushed (which
 * `PositionChange::write()` does for them, immediately), and holds the security console.
 *
 * THE GATE GOES IN `PositionChange::write()`, the single definition all three writers pass
 * through. A rule in `PersonRequest` alone would not reach `App\Support\Roster\RosterImport`, which
 * calls `applyWithoutAudit()` from a `cap:people.manage` route and resolves its position column by
 * NAME against the `positions` table — "Administrator" is a perfectly valid cell.
 *
 * IT IS THE TRANSITION THAT IS GATED, not the value. A `people.manage` holder may still edit an
 * existing Administrator's roster row — their phone number, their level, their name — because the
 * edit form submits the position it was rendered with, and refusing that would leave every
 * administrator's row uneditable by the roster console that exists to edit rows. What they may not
 * do is PLACE somebody at 0 who is not already there, by promotion or by create.
 */
class RosterPositionEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    /**
     * A departmental roster administrator: holds `people.manage` and nothing from the account
     * console. This is the ordinary shape P1c Decision A describes — somebody who maintains who is
     * on the roster and what level they hold.
     */
    private function rosterManager(): User
    {
        $user = User::factory()->create(['position' => 4, 'full_name' => 'Rana Roster']);

        UserCapability::create([
            'user_id' => $user->getKey(),
            'capability_id' => (int) Capability::where('key', 'people.manage')->value('id'),
            'effect' => 'grant',
        ]);

        AccessControl::flush((int) $user->getKey());

        return $user;
    }

    private function admin(): User
    {
        return User::factory()->create(['position' => 0, 'full_name' => 'Aisha Admin']);
    }

    private function requestAs(User $actor): Request
    {
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }

    /** @return array<string, mixed> */
    private function personPayload(Person $person, int $position): array
    {
        return [
            'full_name' => $person->full_name,
            'short_name' => $person->short_name,
            'position' => $position,
            'email' => $person->email,
            'phone' => $person->phone,
            'joined_at' => null,
            'notes' => null,
            'constraints' => [],
            'external' => false,
            'active' => true,
        ];
    }

    /** The whole finding, in one act: the roster gate must not reach the security console. */
    public function test_a_people_manage_holder_cannot_promote_themselves_to_administrator(): void
    {
        $actor = $this->rosterManager();
        $this->assertFalse(AccessControl::allows($actor, 'access.manage'));

        $this->actingAs($actor)
            ->patch('/admin/people/'.$actor->person_id, $this->personPayload($actor->person, 0))
            ->assertSessionHasErrors('position');

        $this->assertSame(4, (int) $actor->person->fresh()->position);

        AccessControl::flush((int) $actor->getKey());
        $this->assertFalse(AccessControl::allows($actor->fresh(), 'access.manage'));
    }

    /** The same gate on the way in — a brand-new roster row is a placement too. */
    public function test_a_people_manage_holder_cannot_create_a_person_at_administrator(): void
    {
        $this->actingAs($this->rosterManager())
            ->post('/admin/people', [
                'full_name' => 'Minted Administrator',
                'short_name' => null,
                'position' => 0,
                'email' => 'minted@example.test',
                'phone' => null,
                'joined_at' => null,
                'notes' => null,
                'constraints' => [],
                'external' => false,
                'active' => true,
            ])
            ->assertSessionHasErrors('position');

        $this->assertDatabaseMissing('people', ['email' => 'minted@example.test']);
    }

    /**
     * THE REASON THE GATE IS IN `PositionChange` AND NOT IN `PersonRequest`.
     *
     * `RosterImport` runs from a `cap:people.manage` route and resolves its position column by name
     * against the `positions` table, so a CSV cell reading "Administrator" is valid input that never
     * passes through a FormRequest rule about positions at all.
     */
    public function test_a_roster_import_cannot_grant_administrator(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'roster').'.csv';
        file_put_contents($path, "Full Name,Position,Email\nSynthetic Person,Administrator,synthetic@example.test\n");

        try {
            $this->expectException(ValidationException::class);

            RosterImport::commit(
                new CsvRosterReader($path),
                ['full_name' => 'Full Name', 'position' => 'Position', 'email' => 'Email'],
                [],
                $this->requestAs($this->rosterManager()),
            );
        } finally {
            $this->assertDatabaseMissing('people', ['email' => 'synthetic@example.test']);
            @unlink($path);
        }
    }

    /**
     * OFFER AND WRITE AGREE (D9 applied to a select). The screen states which positions this viewer
     * may place somebody at; the endpoint refuses exactly the rest.
     */
    public function test_the_people_screen_does_not_offer_administrator_to_a_people_manage_holder(): void
    {
        $this->actingAs($this->rosterManager())->get('/admin/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/People')
                // The full catalog still ships — it is what renders an existing Administrator's
                // role NAME. What narrows is the separate list of positions that may be ASSIGNED.
                ->has('positions', 5)
                ->where('grantable_positions', [2, 3, 4, 5])
            );
    }

    public function test_the_people_screen_offers_administrator_to_a_users_manage_holder(): void
    {
        $this->actingAs($this->admin())->get('/admin/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('grantable_positions', [0, 2, 3, 4, 5])
            );
    }

    /** No regression: the account console's own capability is what the gate asks for. */
    public function test_an_administrator_may_still_place_a_person_at_administrator(): void
    {
        $admin = $this->admin();
        $person = Person::factory()->create(['position' => 4, 'email' => 'promotable@example.test']);

        $this->actingAs($admin)
            ->patch('/admin/people/'.$person->getKey(), $this->personPayload($person, 0))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, (int) $person->fresh()->position);
    }

    /**
     * The transition, not the value. An Administrator's roster row stays editable from the roster
     * console — otherwise narrowing the offer would make every one of those rows unsaveable, since
     * the edit form submits the position it was rendered with.
     */
    public function test_a_people_manage_holder_may_still_edit_an_existing_administrator(): void
    {
        $actor = $this->rosterManager();
        $person = Person::factory()->create([
            'position' => 0,
            'full_name' => 'Sitting Administrator',
            'email' => 'sitting@example.test',
        ]);

        $payload = $this->personPayload($person, 0);
        $payload['phone'] = '+966500000000';

        $this->actingAs($actor)
            ->patch('/admin/people/'.$person->getKey(), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(0, (int) $person->fresh()->position);
        $this->assertSame('+966500000000', $person->fresh()->phone);
    }

    /** The refusal names the unlock rather than just saying no. */
    public function test_the_refusal_names_the_capability_it_wants(): void
    {
        $actor = $this->rosterManager();
        $person = Person::factory()->create(['position' => 4, 'email' => 'target@example.test']);

        try {
            PositionChange::apply($person, 0, $this->requestAs($actor));
            $this->fail('Granting position 0 should have been refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'Administrator',
                (string) $e->validator->errors()->first('position'),
            );
        }

        $this->assertSame(4, (int) $person->fresh()->position);
    }
}
