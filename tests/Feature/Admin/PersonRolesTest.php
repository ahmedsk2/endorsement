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
     * The second door must not be WIDER than the first. Both validate the submitted capability
     * ids against the same catalog and refuse the same unknown effect.
     */
    public function test_the_people_panel_refuses_exactly_what_the_access_control_screen_refuses(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['position' => 4]);

        $this->actingAs($admin)
            ->from('/admin/people')
            ->put('/admin/access-control/person', [
                'person_id' => $target->person_id,
                'overrides' => [999999 => 'grant'],
            ])
            ->assertSessionHasErrors('overrides');

        $this->actingAs($admin)
            ->from('/admin/people')
            ->put('/admin/access-control/person', [
                'person_id' => $target->person_id,
                'overrides' => [$this->capId('endorsement.reopen') => 'sometimes'],
            ])
            ->assertSessionHasErrors('overrides.'.$this->capId('endorsement.reopen'));

        $this->assertDatabaseCount('user_capabilities', 0);
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

            return [
                'status' => $status,
                'still_holds' => AccessControl::allows($admin->fresh(), 'access.manage'),
                'holders' => count(AccessControl::holdersOf('access.manage')),
            ];
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
