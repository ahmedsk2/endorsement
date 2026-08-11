<?php

namespace Tests\Feature\Admin;

use App\Models\Capability;
use App\Models\Invitation;
use App\Models\Person;
use App\Models\User;
use App\Models\UserCapability;
use App\Support\AccessControl;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * AC-04 — "Roles granted per person by Admin, enforced via server-side claims" (P1c-2 Decision F,
 * owner decision 2, 2026-08-10).
 *
 * THE GRANT STAYS ON THE ACCOUNT. `user_capabilities.user_id` does not move to `people`, and
 * `AccessControl::resolve()`, `holdersOf()` and the cache key are untouched. What AC-04 asks for
 * is that an administrator can grant a role WHERE THE PERSON IS — on the roster screen — rather
 * than hunting the same colleague down on a second console. So the People screen becomes a second
 * SURFACE onto the writer Admin -> Access Control already had, and the two cannot drift because
 * there is only one body between them.
 *
 * THE GATE IS `access.manage`, NOT `people.manage`, and that is the whole security content of
 * this task. `people.manage` was scoped as "who exists and what level they hold" — its holder is
 * a departmental administrator who may rename a ward. Serving a role-granting control from the
 * People screen's OWN route group would let that holder grant themselves `access.manage`: a
 * privilege-escalation path created by a UI convenience. The screen is shared; the authorization
 * is not, exactly as it already is for the Invite/Resend controls beside it.
 *
 * AND THE PANEL IS ABSENT, NOT EMPTY, for a viewer who lacks the capability — the discipline
 * `PersonPresenter` uses for a withheld contact field, for the same reason: an empty list and a
 * withheld list look identical on screen, and a future reader eventually renders one as the other.
 */
class PersonRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    private function capId(string $key): int
    {
        return (int) Capability::where('key', $key)->value('id');
    }

    private function admin(): User
    {
        return User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
    }

    /**
     * A departmental roster manager: position 4 by role, holding `people.manage` through a
     * per-user grant and holding `access.manage` through nothing at all. This is the actor the
     * whole task exists to keep out of the capability table.
     */
    private function rosterManager(): User
    {
        $user = User::factory()->create(['position' => 4, 'full_name' => 'Zed Roster']);

        UserCapability::create([
            'user_id' => $user->id,
            'capability_id' => $this->capId('people.manage'),
            'effect' => 'grant',
        ]);
        AccessControl::flush($user->id);

        return $user;
    }

    // ------------------------------------------------------------------ the boundary

    /**
     * The single most important assertion in this task. Without it, `people.manage` becomes a
     * path to `access.manage` and the roster screen is a privilege-escalation door.
     */
    public function test_the_people_screen_grant_endpoint_requires_access_manage_not_people_manage(): void
    {
        $manager = $this->rosterManager();
        $target = User::factory()->create(['position' => 4]);

        // Sanity: this actor really does hold the roster capability, so the refusal below is
        // about `access.manage` and not about a viewer who could not reach the screen at all.
        $this->assertTrue(AccessControl::allows($manager, 'people.manage'));
        $this->assertFalse(AccessControl::allows($manager, 'access.manage'));
        $this->actingAs($manager)->get('/admin/people')->assertOk();

        $this->actingAs($manager)
            ->put('/admin/access-control/person', [
                'person_id' => $target->person_id,
                'overrides' => [$this->capId('access.manage') => 'grant'],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('user_capabilities', [
            'user_id' => $target->id,
            'capability_id' => $this->capId('access.manage'),
        ]);
    }

    /** Absent, not empty — a withheld panel and an empty one must not look the same. */
    public function test_the_panel_is_absent_from_the_props_for_a_people_manage_only_viewer(): void
    {
        $manager = $this->rosterManager();

        $this->actingAs($manager)
            ->get('/admin/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/People')
                ->missing('capability_grants')
            );
    }

    public function test_the_panel_is_present_for_a_viewer_who_holds_access_manage(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['position' => 4, 'full_name' => 'Bee Resident']);

        UserCapability::create([
            'user_id' => $target->id,
            'capability_id' => $this->capId('endorsement.reopen'),
            'effect' => 'grant',
        ]);

        $this->actingAs($admin)
            ->get('/admin/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/People')
                ->has('capability_grants.capabilities')
                ->where('capability_grants.overrides.'.$target->person_id.'.'.$this->capId('endorsement.reopen'), 'grant')
            );
    }

    // ------------------------------------------------------------------ the write

    public function test_a_holder_of_access_manage_can_grant_and_deny_per_person(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['position' => 4]);

        $this->actingAs($admin)
            ->put('/admin/access-control/person', [
                'person_id' => $target->person_id,
                'overrides' => [
                    $this->capId('endorsement.reopen') => 'grant',
                    $this->capId('profile.manage') => 'deny',
                ],
            ])
            ->assertRedirect();

        $this->assertTrue(AccessControl::allows($target->fresh(), 'endorsement.reopen'));
        $this->assertFalse(AccessControl::allows($target->fresh(), 'profile.manage'));
    }

    /** Owner decision 2, asserted: the row lands on the ACCOUNT, and `people` is untouched. */
    public function test_the_grant_lands_on_the_account_not_the_person(): void
    {
        $admin = $this->admin();

        // `people.id` and `users.id` are INDEPENDENT SEQUENCES, and in a fresh database they run
        // in lockstep — which would make "the row landed on the account, not the person"
        // unfalsifiable, because both ids would be the same number. Push them apart first, and
        // assert they really are apart before relying on the distinction. (This case failed on
        // its first run for exactly that reason.)
        Person::factory()->count(3)->create();

        $target = User::factory()->create(['position' => 4]);
        $person = $target->person;
        $this->assertNotSame((int) $person->id, (int) $target->id,
            'the fixture put the person and the account on the same id — the assertions below prove nothing');
        $before = $person->fresh()->toArray();

        $this->actingAs($admin)
            ->put('/admin/access-control/person', [
                'person_id' => $person->id,
                'overrides' => [$this->capId('endorsement.reopen') => 'grant'],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_capabilities', [
            'user_id' => $target->id,
            'capability_id' => $this->capId('endorsement.reopen'),
            'effect' => 'grant',
        ]);

        // `people.id` and `users.id` are independent sequences, so "the row landed on the
        // account" is only meaningful if the person's own id could NOT have satisfied it.
        $this->assertDatabaseMissing('user_capabilities', ['user_id' => $person->id]);
        $this->assertSame($before, $person->fresh()->toArray(), 'the roster row was written by a capability grant');
    }

    public function test_a_person_with_no_account_is_refused_with_a_message_not_a_500(): void
    {
        $admin = $this->admin();
        $person = Person::factory()->create(['full_name' => 'Roster Only']);

        $this->actingAs($admin)
            ->from('/admin/people')
            ->put('/admin/access-control/person', [
                'person_id' => $person->id,
                'overrides' => [$this->capId('endorsement.reopen') => 'grant'],
            ])
            ->assertRedirect('/admin/people')
            ->assertSessionHasErrors('person_id');

        $this->assertDatabaseCount('user_capabilities', 0);
    }

    /**
     * The resolver's two-pass rule is unchanged by the second surface. This PROVES "no resolver
     * change" rather than asserting it in a comment.
     */
    public function test_deny_still_wins_over_grant_through_the_new_surface(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['position' => 4]);

        // `profile.manage` is a position-4 role default, so a deny has something to beat.
        $this->assertTrue(AccessControl::allows($target, 'profile.manage'));

        $this->actingAs($admin)
            ->put('/admin/access-control/person', [
                'person_id' => $target->person_id,
                'overrides' => [$this->capId('profile.manage') => 'deny'],
            ])
            ->assertRedirect();

        $this->assertFalse(AccessControl::allows($target->fresh(), 'profile.manage'));
    }

    public function test_the_cache_is_flushed_for_that_account(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['position' => 4]);

        // Warm the resolved set for the target BEFORE the write, so a missing flush leaves the
        // stale answer behind rather than merely never having been computed.
        $this->assertFalse(AccessControl::allows($target, 'endorsement.reopen'));

        $this->actingAs($admin)
            ->put('/admin/access-control/person', [
                'person_id' => $target->person_id,
                'overrides' => [$this->capId('endorsement.reopen') => 'grant'],
            ])
            ->assertRedirect();

        $this->assertTrue(AccessControl::allows($target->fresh(), 'endorsement.reopen'));
    }

    /** The existing actions, reused — a second surface does not get a second vocabulary. */
    public function test_the_write_is_audited_as_a_summary_plus_one_row_per_changed_override(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['position' => 4]);

        $this->actingAs($admin)
            ->put('/admin/access-control/person', [
                'person_id' => $target->person_id,
                'overrides' => [
                    $this->capId('endorsement.reopen') => 'grant',
                    $this->capId('profile.manage') => 'deny',
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'access_user_update',
            'user_id' => $admin->id,
            'detail' => 'user='.$target->id.';overrides=2',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'access_user_grant',
            'detail' => 'user='.$target->id.';cap=endorsement.reopen',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'access_user_deny',
            'detail' => 'user='.$target->id.';cap=profile.manage',
        ]);

        // Returning to inherit is named too, and by the SAME action the other console writes.
        $this->actingAs($admin)
            ->put('/admin/access-control/person', [
                'person_id' => $target->person_id,
                'overrides' => [],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'access_user_override_clear',
            'detail' => 'user='.$target->id.';cap=endorsement.reopen',
        ]);
    }

    /** One writer, two surfaces, proved rather than asserted. */
    public function test_the_access_control_screen_and_the_people_panel_produce_identical_rows(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['position' => 4]);
        $capId = $this->capId('endorsement.reopen');

        $this->actingAs($admin)
            ->put('/admin/access-control/user', [
                'user_id' => $target->id,
                'overrides' => [$capId => 'grant'],
            ])
            ->assertRedirect();

        $viaUserScreen = UserCapability::where('user_id', $target->id)
            ->get(['user_id', 'capability_id', 'effect'])
            ->map(fn (UserCapability $row): array => $row->only(['user_id', 'capability_id', 'effect']))
            ->all();

        // Back to nothing, then the same grant through the other door.
        UserCapability::where('user_id', $target->id)->delete();
        AccessControl::flush($target->id);

        $this->actingAs($admin)
            ->put('/admin/access-control/person', [
                'person_id' => $target->person_id,
                'overrides' => [$capId => 'grant'],
            ])
            ->assertRedirect();

        $viaPeopleScreen = UserCapability::where('user_id', $target->id)
            ->get(['user_id', 'capability_id', 'effect'])
            ->map(fn (UserCapability $row): array => $row->only(['user_id', 'capability_id', 'effect']))
            ->all();

        $this->assertSame($viaUserScreen, $viaPeopleScreen);
        $this->assertNotSame([], $viaPeopleScreen, 'neither surface wrote anything — the comparison proved nothing');
    }

    /**
     * The second door must not be WIDER OR NARROWER than the first: both validate the submitted
     * capability ids against the same catalog and refuse the same unknown effect.
     *
     * IT USED TO NAME THAT PROPERTY AND NEVER TEST IT (review F10). Both halves posted to
     * `/admin/access-control/person` — the People panel, twice — so the access-control screen it
     * claims parity WITH was never opened, and a refusal added to one door alone would have left
     * this green. It is the same defect P1c-2 finding 4 recorded elsewhere in this codebase:
     * something declared and never exercised, which reads in review exactly like something
     * enforced.
     *
     * Every case now goes through BOTH endpoints and the two outcomes are compared as values —
     * status, error keys, and the rows each actually wrote — rather than asserted separately
     * against a hand-written expectation each side could drift from independently. Proved by
     * planting a refusal in `updatePerson()` alone and watching this go red.
     */
    public function test_the_people_panel_refuses_exactly_what_the_access_control_screen_refuses(): void
    {
        $reopen = $this->capId('endorsement.reopen');

        foreach ([
            'an unknown capability id' => [999999 => 'grant'],
            'an unknown effect' => [$reopen => 'sometimes'],
            'a known id with an empty effect' => [$reopen => ''],
            'a well-formed grant, which BOTH must accept' => [$reopen => 'grant'],
        ] as $label => $overrides) {
            $viaAccountConsole = $this->submit('user', $overrides);
            $viaPeoplePanel = $this->submit('person', $overrides);

            $this->assertSame($viaAccountConsole, $viaPeoplePanel,
                "The two doors disagree about {$label}.");
        }
    }

    /**
     * Put one override map through ONE of the two doors and project what came back, in a shape
     * the other door's answer can be compared to.
     *
     * A fresh target per call, and the rows are read back by (capability_id, effect) rather than
     * by row id: the two doors write through the same writer to the same account, but they write
     * DIFFERENT accounts here, and `user_capabilities.id` would differ on every comparison for a
     * reason that has nothing to do with the property.
     *
     * @param  'user'|'person'  $door
     * @param  array<int, string>  $overrides
     * @return array{status: int, errors: list<string>, rows: list<array{capability_id: int, effect: string}>}
     */
    private function submit(string $door, array $overrides): array
    {
        $admin = $this->admin();
        $target = User::factory()->create(['position' => 4]);

        $payload = $door === 'user'
            ? ['user_id' => $target->id, 'overrides' => $overrides]
            : ['person_id' => $target->person_id, 'overrides' => $overrides];

        $response = $this->actingAs($admin)
            ->from($door === 'user' ? '/admin/access-control' : '/admin/people')
            ->put('/admin/access-control/'.$door, $payload);

        // Read the bag the way `TestResponse::assertSessionHasErrors()` does, START INCLUDED.
        // `session('errors')` and a bare `app('session.store')->get('errors')` both answer here
        // with the store's raw pre-start array (`['default' => ['format' => …, 'messages' => …]]`)
        // rather than a `ViewErrorBag`, and `->getBag()` on that is a fatal, not a failure —
        // measured, not assumed. The framework's own accessor starts the store first, which is
        // what deserialises it.
        $session = app('session.store');

        if (! $session->isStarted()) {
            $session->start();
        }

        $bag = $session->get('errors');

        // The BOUND key differs by construction (`user_id` vs `person_id`) and is not part of the
        // property — only the keys describing the submitted MAP are, which is what both doors
        // validate identically.
        $errors = array_values(array_filter(
            array_keys($bag instanceof ViewErrorBag ? $bag->getBag('default')->getMessages() : []),
            static fn (string $key): bool => $key === 'overrides' || str_starts_with($key, 'overrides.'),
        ));
        sort($errors);

        $rows = UserCapability::where('user_id', $target->id)
            ->orderBy('capability_id')
            ->get(['capability_id', 'effect'])
            ->map(static fn (UserCapability $row): array => [
                'capability_id' => (int) $row->capability_id,
                'effect' => (string) $row->effect,
            ])
            ->all();

        return ['status' => $response->status(), 'errors' => $errors, 'rows' => $rows];
    }

    // ------------------------------------------------------------------ the escalation surface

    /**
     * THE SECOND DOOR MUST NOT BE A WEAKER DOOR ONTO THE SAME TABLE, and this is the case that
     * says so about the one thing `access.manage` can do to itself.
     *
     * MEASURED, NOT ASSUMED (P1c-2 Task 6). `AccessControlController::assertNoSelfLockout()`
     * protects the Administrator ROLE's default set — position 0 may not give up
     * `access.manage` on the role matrix — and it does NOT run on the per-user override path at
     * all. So an administrator has always been able to deny `access.manage` to themselves, and
     * to the last account holding it, from Admin -> Access Control: after which
     * `AccessControl::holdersOf('access.manage')` answers nobody and the screen is unreachable
     * without database access. That is a PRE-EXISTING gap on the account console. Task 6's
     * extraction is behaviour-preserving and deliberately does not close it — adding a refusal
     * would be a new behaviour, on a path whose existing tests must pass untouched.
     *
     * What this case DOES pin is that the People panel is neither wider nor narrower about it.
     * It asserts the two doors AGREE, not that either permits — so when a lockout guard is
     * eventually added to `App\Support\CapabilityGrant` (which is the only place it can be added,
     * now that both surfaces share one body), this case stays green and becomes the proof that
     * the guard reached both.
     *
     * THE GUARD ARRIVED ON 2026-08-11, AND THIS CASE NEEDED A FIXTURE FIX TO SURVIVE IT — which
     * the sentence above did not anticipate and which is worth stating rather than quietly
     * patching. The two closures share ONE database (`RefreshDatabase` wraps the whole method),
     * and the property they compare is measured against it. While both doors PERMITTED the deny
     * that was harmless: each closure's administrator denied themselves, each left zero holders,
     * and the two answers matched. Once both doors REFUSE, the first closure's administrator
     * survives as a holder — so the second closure's administrator is no longer the LAST one, its
     * deny is correctly permitted, and the case went red reporting a disagreement that was
     * really a difference in the two worlds. It was measuring fixture order, not the doors.
     *
     * `$admin->delete()` is what restores the symmetry: `AccessControl::holdersOf()` runs
     * `User::query()`, so the SoftDeletes scope drops a trashed account and the second closure
     * opens on the same empty world the first one did. Nothing in the application soft-deletes a
     * `users` row (accounts are unbound and deactivated, never deleted) — this is a test tearing
     * down its own fixture, not a path being exercised.
     */
    public function test_the_two_doors_agree_about_denying_the_last_access_manage_holder(): void
    {
        $capId = $this->capId('access.manage');

        $viaAccountConsole = (function () use ($capId): array {
            $admin = User::factory()->create(['position' => 0, 'member_name' => 'lockout-a']);
            $status = $this->actingAs($admin)
                ->from('/admin/access-control')
                ->put('/admin/access-control/user', [
                    'user_id' => $admin->id,
                    'overrides' => [$capId => 'deny'],
                ])->status();

            $measured = [
                'status' => $status,
                'still_holds' => AccessControl::allows($admin->fresh(), 'access.manage'),
                'holders' => count(AccessControl::holdersOf('access.manage')),
            ];

            // Hand the second door the same world this one opened on — see the docblock.
            $admin->delete();

            return $measured;
        })();

        $viaPeoplePanel = (function () use ($capId): array {
            $admin = User::factory()->create(['position' => 0, 'member_name' => 'lockout-b']);
            $status = $this->actingAs($admin)
                ->from('/admin/people')
                ->put('/admin/access-control/person', [
                    'person_id' => $admin->person_id,
                    'overrides' => [$capId => 'deny'],
                ])->status();

            return [
                'status' => $status,
                'still_holds' => AccessControl::allows($admin->fresh(), 'access.manage'),
                'holders' => count(AccessControl::holdersOf('access.manage')),
            ];
        })();

        $this->assertSame($viaAccountConsole, $viaPeoplePanel,
            'the People panel and the account console disagree about denying access.manage — one of '
            .'them is a weaker door onto the same table');
    }

    // ------------------------------------------------------------------ the consequence

    /**
     * Owner decision 2's deliberate consequence, asserted so it cannot be "fixed" later by
     * somebody who reads it as a bug. Auto-restoring privileges when a person is re-bound to a
     * new account means a departed administrator's grants silently reattach to whoever claims
     * that identity next, and nobody reviews a restore that nobody performed.
     */
    public function test_unbinding_then_re_inviting_does_not_restore_old_roles(): void
    {
        $admin = $this->admin();
        $person = Person::factory()->create(['full_name' => 'Dr Returning', 'email' => 'returning@example.org', 'position' => 4]);
        $first = User::factory()->create(['person_id' => $person->id, 'member_name' => 'returning1']);

        $this->actingAs($admin)
            ->put('/admin/access-control/person', [
                'person_id' => $person->id,
                'overrides' => [$this->capId('endorsement.reopen') => 'grant'],
            ])
            ->assertRedirect();
        $this->assertTrue(AccessControl::allows($first->fresh(), 'endorsement.reopen'));

        $this->actingAs($admin)->patch("/admin/users/{$first->id}/unbind")->assertRedirect();

        [, $token] = Invitation::issue('returning@example.org', 4, $admin, $person);
        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Returning',
            'member_name' => 'returning2',
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login');

        $second = User::where('member_name', 'returning2')->firstOrFail();
        $this->assertSame($person->id, (int) $second->person_id);
        $this->assertNotSame($first->id, $second->id);

        $this->assertSame(0, UserCapability::where('user_id', $second->id)->count());
        $this->assertFalse(AccessControl::allows($second, 'endorsement.reopen'));

        // The old row is KEPT — it is history, and deleting it would be "auto-restore" inverted.
        $this->assertDatabaseHas('user_capabilities', [
            'user_id' => $first->id,
            'capability_id' => $this->capId('endorsement.reopen'),
        ]);
    }
}
