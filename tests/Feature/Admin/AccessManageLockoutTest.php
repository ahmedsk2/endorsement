<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\User;
use App\Models\UserCapability;
use App\Support\AccessControl;
use App\Support\CapabilityGrant;
use App\Support\Roster\CsvRosterReader;
use App\Support\Roster\RosterImport;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * `access.manage` MUST ALWAYS HAVE AN ACTIVE HOLDER — the per-user override path, which is where
 * that was never true.
 *
 * THE GAP THIS CLOSES, MEASURED BEFORE IT WAS FIXED (P1c-2 Task 6, then this branch).
 * `AccessControlController::assertNoSelfLockout()` protects the ROLE MATRIX alone: position 0 may
 * not give up `access.manage` via `updateRole()`/`updateRoles()`. It never ran on the per-user
 * override path. So an administrator could `PUT /admin/access-control/user` with
 * `overrides => [<access.manage> => 'deny']` against their own account, receive a 302, and leave
 * `AccessControl::holdersOf('access.manage')` answering NOBODY — after which Admin → Access
 * Control is unreachable and access control can never be edited again without opening the
 * database by hand. AC-04's People-screen panel inherited the same gap exactly, because there is
 * one writer behind both surfaces.
 *
 * WHY THE GUARD IS IN `App\Support\CapabilityGrant` AND NOT IN THE CONTROLLER. Both surfaces
 * delegate to `applyForUser()`; a refusal in `updateUser()` would leave `updatePerson()` open, and
 * a refusal in both would be the two-copies-that-drift shape this codebase has already paid for
 * twice (the audit canonical string, the picker predicates). One writer, one guard.
 *
 * WHY IT ASKS `holdersOf()` RATHER THAN REASONING ABOUT THE SUBMITTED MAP. `holdersOf()` already
 * computes "who effectively holds this capability" — role default, then per-user grant, then
 * per-user deny, in `resolve()`'s own order — and is deliberately uncached. Re-deriving that from
 * the submitted overrides would be a fourth copy of the resolution rules, and it would be wrong in
 * a way no test would notice: the hazard is not the WORD "deny". Clearing a per-user GRANT that
 * was somebody's only claim strips the capability just as completely, and an omitted key is how
 * this API spells that.
 */
class AccessManageLockoutTest extends TestCase
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

    /** @return list<int> the user ids `holdersOf()` currently answers with */
    private function holderIds(): array
    {
        return array_map(
            static fn (User $u): int => (int) $u->getKey(),
            AccessControl::holdersOf('access.manage'),
        );
    }

    // ------------------------------------------------------------------ the gap itself

    /** The measured lockout: the account console, denying the capability to its last holder. */
    public function test_the_account_console_refuses_to_deny_the_last_access_manage_holder(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $this->assertSame([$admin->id], $this->holderIds(), 'the fixture never held access.manage');

        $this->actingAs($admin)
            ->from('/admin/access-control')
            ->put('/admin/access-control/user', [
                'user_id' => $admin->id,
                'overrides' => [$this->capId('access.manage') => 'deny'],
            ])
            ->assertSessionHasErrors('overrides');

        $this->assertDatabaseMissing('user_capabilities', [
            'user_id' => $admin->id,
            'capability_id' => $this->capId('access.manage'),
        ]);
        $this->assertSame([$admin->id], $this->holderIds());
    }

    /** The same refusal through the People panel — one writer, so it cannot be a weaker door. */
    public function test_the_people_panel_refuses_to_deny_the_last_access_manage_holder(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)
            ->from('/admin/people')
            ->put('/admin/access-control/person', [
                'person_id' => $admin->person_id,
                'overrides' => [$this->capId('access.manage') => 'deny'],
            ])
            ->assertSessionHasErrors('overrides');

        $this->assertSame([$admin->id], $this->holderIds());
    }

    /**
     * NOT A DENY AT ALL. An account whose only claim on `access.manage` is a per-user GRANT loses
     * it when that override is cleared, and an omitted key is how this API spells "back to
     * inherit". A guard written against the submitted map's `'deny'` values would pass this and
     * lock the department out anyway.
     */
    public function test_clearing_the_grant_that_is_somebodys_only_claim_is_refused_too(): void
    {
        // No position-0 account exists here, so the role default grants `access.manage` to nobody
        // and this per-user grant is the whole of it.
        $holder = User::factory()->create(['position' => 3]);
        UserCapability::create([
            'user_id' => $holder->id,
            'capability_id' => $this->capId('access.manage'),
            'effect' => 'grant',
        ]);
        AccessControl::flush($holder->id);
        $this->assertSame([$holder->id], $this->holderIds());

        $this->actingAs($holder)
            ->from('/admin/access-control')
            ->put('/admin/access-control/user', [
                'user_id' => $holder->id,
                'overrides' => [],
            ])
            ->assertSessionHasErrors('overrides');

        $this->assertDatabaseHas('user_capabilities', [
            'user_id' => $holder->id,
            'capability_id' => $this->capId('access.manage'),
            'effect' => 'grant',
        ]);
        $this->assertSame([$holder->id], $this->holderIds());
    }

    // ------------------------------------------------------------------ what it does NOT refuse

    /**
     * The guard is about the SET, not about the act. Giving up `access.manage` while a colleague
     * still holds it is a perfectly ordinary thing for a departing administrator to do, and a
     * guard that refused it would make the capability unrevocable.
     */
    public function test_an_administrator_may_deny_themselves_while_another_active_holder_remains(): void
    {
        $leaving = User::factory()->create(['position' => 0]);
        $staying = User::factory()->create(['position' => 0]);

        $this->actingAs($leaving)
            ->from('/admin/access-control')
            ->put('/admin/access-control/user', [
                'user_id' => $leaving->id,
                'overrides' => [$this->capId('access.manage') => 'deny'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertFalse(AccessControl::allows($leaving->fresh(), 'access.manage'));
        $this->assertSame([$staying->id], $this->holderIds());
    }

    /**
     * An unrelated override on the last holder's own account is not a lockout and must not be
     * refused — the guard asks whether `access.manage` survived, not whether anything changed.
     */
    public function test_an_unrelated_override_on_the_last_holders_account_still_saves(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)
            ->put('/admin/access-control/user', [
                'user_id' => $admin->id,
                'overrides' => [$this->capId('endorsement.reopen') => 'deny'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('user_capabilities', [
            'user_id' => $admin->id,
            'capability_id' => $this->capId('endorsement.reopen'),
            'effect' => 'deny',
        ]);
        $this->assertSame([$admin->id], $this->holderIds());
    }

    // ------------------------------------------------------------------ who counts as a holder

    /**
     * A DORMANT ADMINISTRATOR IS NOT COVER. `holdersOf()` excludes inactive accounts because they
     * cannot log in, and the guard inherits that: an administrator whose only apparent colleague
     * has been deactivated is still the last holder, and a guard counting `people.position = 0`
     * rows instead would have waved this through.
     */
    public function test_an_inactive_administrator_does_not_count_as_a_remaining_holder(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        User::factory()->inactive()->create(['position' => 0]);

        $this->assertSame([$admin->id], $this->holderIds());

        $this->actingAs($admin)
            ->from('/admin/access-control')
            ->put('/admin/access-control/user', [
                'user_id' => $admin->id,
                'overrides' => [$this->capId('access.manage') => 'deny'],
            ])
            ->assertSessionHasErrors('overrides');
    }

    /**
     * Nor is a RETIRED or an UNBOUND account, and this is stated as a test because the guard now
     * trusts `holdersOf()` to answer "who could actually act".
     *
     * `holdersOf()` runs `User::query()` (so the SoftDeletes scope drops a trashed account) and
     * INNER JOINS `people` on `users.person_id` (so an unbound account, whose link
     * `App\Support\AccountUnbind` has cleared, drops out with it — before `users.active` is even
     * consulted). Both were already true; neither had ever been asserted.
     */
    public function test_a_trashed_or_unbound_account_is_not_a_holder(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $trashed = User::factory()->create(['position' => 0]);
        $trashed->delete();

        // Unbound but still flagged active — the state `AccountUnbind` never leaves behind (it
        // deactivates in the same transaction), constructed here so the JOIN is what excludes it
        // rather than the `users.active` predicate.
        $unbound = User::factory()->create(['position' => 0]);
        $unbound->forceFill(['person_id' => null])->save();

        $this->assertSame([$admin->id], $this->holderIds());

        $this->actingAs($admin)
            ->from('/admin/access-control')
            ->put('/admin/access-control/user', [
                'user_id' => $admin->id,
                'overrides' => [$this->capId('access.manage') => 'deny'],
            ])
            ->assertSessionHasErrors('overrides');
    }

    // ------------------------------------------------------------------ not merely self-lockout

    /**
     * THE GUARD READS THE HOLDER SET, NOT THE ACTOR — A denying B, where B is the last holder.
     *
     * In the tree as it stands this is defence in depth rather than a reachable path, and saying
     * so is the point of this docblock. Any actor who reaches the writer has passed
     * `cap:access.manage` and `EnsureAccountActive`, so they are themselves an active holder, and
     * a deny aimed at anybody ELSE therefore always leaves at least the actor behind. The case is
     * only constructible by calling the writer with an actor `holdersOf()` cannot see — here, an
     * inactive one.
     *
     * It is still the right shape for the guard. "Nobody is left" and "I am not left" are
     * different questions, and the reason the second happens to answer the first today is an
     * agreement between `capabilitiesFor()` (which does not consult `users.active` or the person
     * link) and `holdersOf()` (which consults both) that nothing enforces. A guard phrased as
     * "you may not deny yourself" would silently stop covering the case the day those two
     * diverge — and it would cost exactly the same query either way.
     */
    public function test_the_writer_refuses_a_deny_aimed_at_somebody_else_who_is_the_last_holder(): void
    {
        $actor = User::factory()->inactive()->create(['position' => 0]);
        $lastHolder = User::factory()->create(['position' => 0]);

        $this->assertSame([$lastHolder->id], $this->holderIds(),
            'the inactive actor was counted as a holder — this case proves nothing');

        try {
            CapabilityGrant::applyForUser(
                $lastHolder,
                [$this->capId('access.manage') => 'deny'],
                $actor,
                '127.0.0.1',
            );
            $this->fail('the writer allowed the last access.manage holder to be denied by somebody else');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('overrides', $e->errors());
        }

        $this->assertSame([$lastHolder->id], $this->holderIds());
    }

    // -------------------------------------------------- the account-lifecycle doors (ruling 45)

    /**
     * THE PRECONDITION EVERY LIFECYCLE CASE BELOW SHARES, and the whole reason ruling 44 did not
     * close them.
     *
     * Two accounts sit at position 0; one has been DENIED `access.manage`. Ruling 44's guard
     * permits that deny and is right to — the administrator performing it still holds the
     * capability, and a departing colleague must be able to give it up. What it leaves behind is
     * a PHANTOM ADMINISTRATOR: an active account at position 0 that holds nothing.
     *
     * To a ROLE-shaped guard the phantom is cover. `isLastActiveAdministrator()` asked "is another
     * active `people.position = 0` account left" and answered yes, so every one of the five doors
     * below let the real holder be retired and left `holdersOf('access.manage')` answering nobody.
     * Measured on 2026-08-11 against the tree as it stood: each returned 302 and emptied the set.
     *
     * @return array{0: User, 1: User} [the last real holder, the phantom]
     */
    private function holderAndPhantom(): array
    {
        $holder = User::factory()->create([
            'position' => 0, 'full_name' => 'AAA Holder', 'member_name' => 'holder',
        ]);
        $phantom = User::factory()->create([
            'position' => 0, 'full_name' => 'ZZZ Phantom', 'member_name' => 'phantom',
        ]);

        UserCapability::create([
            'user_id' => $phantom->id,
            'capability_id' => $this->capId('access.manage'),
            'effect' => 'deny',
        ]);
        AccessControl::flush($phantom->id);

        $this->assertSame([$holder->id], $this->holderIds(),
            'the fixture does not leave exactly one holder — every case below would prove nothing');

        // ... while the ROLE question still answers "there is cover", which is what made all five
        // doors open. Asserted through the columns rather than through any predicate, so this
        // stays true whatever happens to the predicates themselves.
        $this->assertTrue((bool) $phantom->fresh()->active);
        $this->assertSame(0, (int) $phantom->fresh()->position);

        return [$holder, $phantom];
    }

    /** The roster console's full field set (`PersonRequest`), for the one door that PATCHes it. */
    private function personPayload(array $overrides = []): array
    {
        return $overrides + [
            'full_name' => 'Some Body',
            'short_name' => null,
            'position' => 0,
            'email' => null,
            'phone' => null,
            'joined_at' => null,
            'notes' => null,
            'constraints' => null,
            'external' => false,
            'active' => true,
        ];
    }

    /** Door 1 — `PATCH /admin/users/{u}/active`, the account console's deactivate. */
    public function test_deactivating_the_last_access_manage_holder_is_refused(): void
    {
        [$holder] = $this->holderAndPhantom();
        $auditBefore = AuditLog::count();

        $this->actingAs($holder)
            ->from('/admin/users')
            ->patch('/admin/users/'.$holder->id.'/active', ['active' => false])
            ->assertSessionHasErrors('active');

        $this->assertTrue((bool) $holder->fresh()->active);
        $this->assertTrue((bool) $holder->person->fresh()->active,
            'the person flag must not be half-applied either');
        $this->assertSame([$holder->id], $this->holderIds());
        $this->assertSame($auditBefore, AuditLog::count(),
            'a refused deactivation must leave no audit row claiming it happened');
    }

    /** Door 2 — `PATCH /admin/users/{u}/unbind`, AC-03's turnover. */
    public function test_unbinding_the_last_access_manage_holder_is_refused(): void
    {
        [$holder] = $this->holderAndPhantom();

        $this->actingAs($holder)
            ->from('/admin/users')
            ->patch('/admin/users/'.$holder->id.'/unbind')
            ->assertSessionHasErrors('unbind');

        $this->assertNotNull($holder->fresh()->person_id);
        $this->assertTrue((bool) $holder->fresh()->active);
        $this->assertSame([$holder->id], $this->holderIds());
        $this->assertDatabaseMissing('audit_log', ['action' => 'account_unbound']);
    }

    /** Door 3 — `PATCH /admin/users/{u}/position`, demotion from the ACCOUNT console. */
    public function test_demoting_the_last_access_manage_holder_from_the_account_console_is_refused(): void
    {
        [$holder] = $this->holderAndPhantom();

        $this->actingAs($holder)
            ->from('/admin/users')
            ->patch('/admin/users/'.$holder->id.'/position', ['position' => 4])
            ->assertSessionHasErrors('position');

        $this->assertSame(0, (int) $holder->person->fresh()->position);
        $this->assertSame([$holder->id], $this->holderIds());
    }

    /** Door 4 — `PATCH /admin/people/{p}`, the SAME demotion from the ROSTER console. */
    public function test_demoting_the_last_access_manage_holder_from_the_roster_console_is_refused(): void
    {
        [$holder] = $this->holderAndPhantom();

        $this->actingAs($holder)
            ->from('/admin/people')
            ->patch('/admin/people/'.$holder->person_id, $this->personPayload(['position' => 4]))
            ->assertSessionHasErrors('position');

        $this->assertSame(0, (int) $holder->person->fresh()->position);
        $this->assertSame('AAA Holder', $holder->person->fresh()->full_name,
            'the rest of the edit must roll back with the refusal, not save around it');
        $this->assertSame([$holder->id], $this->holderIds());
    }

    /**
     * Door 5 — `POST /admin/people/bulk` with `set_active`. Ruling 45 recorded this one as NOT
     * conclusively measured and told the next reader to presume the same blind spot until it was.
     * It is measured here: `wouldLeaveNoActiveAdministrator()` excluded the selected account and
     * found the phantom, exactly as its per-row sibling did.
     */
    public function test_a_bulk_deactivation_that_would_take_the_last_holder_is_refused(): void
    {
        [$holder] = $this->holderAndPhantom();

        $this->actingAs($holder)
            ->from('/admin/people')
            ->post('/admin/people/bulk', [
                'action' => 'set_active',
                'active' => false,
                'ids' => [$holder->person_id],
            ])
            ->assertSessionHasErrors('ids');

        $this->assertTrue((bool) $holder->fresh()->active);
        $this->assertTrue((bool) $holder->person->fresh()->active);
        $this->assertSame([$holder->id], $this->holderIds());
    }

    /**
     * The bulk door's SET-AWARENESS survives the change of predicate (P1c finding 13). Two real
     * holders selected together: each looks survivable alone, and the batch must still be refused
     * — the property the oracle keeps for free, because each row's write is visible to the next
     * row's question inside the one transaction.
     */
    public function test_a_bulk_deactivation_of_every_holder_at_once_is_refused_as_a_set(): void
    {
        $first = User::factory()->create(['position' => 0, 'full_name' => 'AAA One']);
        $second = User::factory()->create(['position' => 0, 'full_name' => 'BBB Two']);

        $this->actingAs($first)
            ->from('/admin/people')
            ->post('/admin/people/bulk', [
                'action' => 'set_active',
                'active' => false,
                'ids' => [$first->person_id, $second->person_id],
            ])
            ->assertSessionHasErrors('ids');

        $this->assertTrue((bool) $first->fresh()->active);
        $this->assertTrue((bool) $second->fresh()->active);
        $this->assertCount(2, $this->holderIds());
    }

    // ------------------------------------------------ what the lifecycle doors must NOT refuse

    /**
     * All five doors still open when a SECOND account really holds the capability. A guard that
     * refused here would make an administrator unretirable, which is the failure mode opposite to
     * the one being closed and just as real — turnover is the thing AC-03 exists to enable.
     *
     * Each door gets its own pair, and the survivors accumulate across iterations, which is
     * harmless in the permissive direction (more holders, never fewer) — unlike the refusal cases
     * above, which is why those are separate methods. `PersonRolesTest`'s two-doors case records
     * the same fixture-order trap from the other side.
     */
    public function test_every_lifecycle_door_still_opens_while_another_active_holder_remains(): void
    {
        $doors = [
            'deactivate' => function (User $actor, User $target): void {
                $this->actingAs($actor)->patch('/admin/users/'.$target->id.'/active', ['active' => false])
                    ->assertSessionHasNoErrors();
                $this->assertFalse((bool) $target->fresh()->active);
            },
            'unbind' => function (User $actor, User $target): void {
                $this->actingAs($actor)->patch('/admin/users/'.$target->id.'/unbind')
                    ->assertSessionHasNoErrors();
                $this->assertNull($target->fresh()->person_id);
            },
            'position (account console)' => function (User $actor, User $target): void {
                $this->actingAs($actor)->patch('/admin/users/'.$target->id.'/position', ['position' => 4])
                    ->assertSessionHasNoErrors();
                $this->assertSame(4, (int) $target->person->fresh()->position);
            },
            'position (roster console)' => function (User $actor, User $target): void {
                $this->actingAs($actor)->patch('/admin/people/'.$target->person_id,
                    $this->personPayload(['position' => 4, 'full_name' => 'Still Here']))
                    ->assertSessionHasNoErrors();
                $this->assertSame(4, (int) $target->person->fresh()->position);
            },
            'bulk set_active' => function (User $actor, User $target): void {
                $this->actingAs($actor)->post('/admin/people/bulk', [
                    'action' => 'set_active', 'active' => false, 'ids' => [$target->person_id],
                ])->assertSessionHasNoErrors();
                $this->assertFalse((bool) $target->fresh()->active);
            },
        ];

        $n = 0;

        foreach ($doors as $label => $door) {
            $n++;
            $staying = User::factory()->create(['position' => 0, 'member_name' => 'stay'.$n]);
            $leaving = User::factory()->create(['position' => 0, 'member_name' => 'go'.$n]);

            $this->assertContains($staying->id, $this->holderIds(), "{$label}: fixture");

            $door($staying, $leaving);

            $this->assertContains($staying->id, $this->holderIds(),
                "the {$label} door refused, or removed the wrong holder");
        }
    }

    /**
     * THE PREDICATE REALLY CHANGED, and this is the case that says so out loud.
     *
     * The old guard asked about the Administrator ROLE, so it refused this: the sole position-0
     * account stepping down. The new one asks about the CAPABILITY, and a consultant holding
     * `access.manage` through a per-user grant is a perfectly good holder — that is what
     * `holdersOf()` has always answered and what every refusal message in this system already
     * names. Refusing here would be protecting a role nothing depends on any more; `access.manage`
     * is the recovery root, because its holder can grant every other capability back.
     */
    public function test_the_last_administrator_may_step_down_once_somebody_else_holds_the_capability(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        $consultant = User::factory()->create(['position' => 3, 'full_name' => 'ZZZ Consultant']);

        UserCapability::create([
            'user_id' => $consultant->id,
            'capability_id' => $this->capId('access.manage'),
            'effect' => 'grant',
        ]);
        AccessControl::flush($consultant->id);

        $this->actingAs($admin)
            ->from('/admin/users')
            ->patch('/admin/users/'.$admin->id.'/position', ['position' => 3])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, (int) $admin->person->fresh()->position);
        $this->assertSame([$consultant->id], $this->holderIds(),
            'the capability survived the last Administrator leaving the role');
    }

    // ------------------------------------------------------------------ what the guard costs

    /**
     * THE COST OF PUTTING THE GUARD IN `PositionChange::write()`, MEASURED WHERE IT WOULD HAVE
     * HURT. `AccessControl::holdersOf()` is deliberately uncached and runs five queries, and the
     * roster importer calls `PositionChange::applyWithoutAudit()` ONCE PER ROW — so a guard that
     * asked on every write would put a five-query N+1 inside the one loop in this codebase that
     * can be hundreds of rows long. That is the cost ruling 45 flagged and told the next reader to
     * measure rather than assume.
     *
     * IT IS ZERO, and it is zero structurally rather than by luck. `holdersOf()` INNER JOINS
     * `people` on `users.person_id`, so a person with no account contributes no holder and a write
     * touching only their roster row cannot remove one — which is what `AccessManageGuard::
     * guarding()`'s null `$couldLose` says. And an import can only ever reach such a person:
     * `RosterImport::SKIP_HAS_ACCOUNT` refuses every row matching somebody who has an account
     * (a spreadsheet must not rename an account holder), and a create makes a person with no
     * account by construction — `RosterNeverMintsCredentialsTest` is what keeps it that way.
     *
     * ASSERTED AS "THE ORACLE WAS NEVER ASKED", not as a query count. `holdersOf()` opens with a
     * lookup against `capabilities`, and no other part of an import touches that table — so the
     * absence of it is exact, and it stays exact when an unrelated change moves the import's own
     * query count. Both halves of the import's reachable world are exercised: rows that CREATE,
     * and a row that is skipped for having an account.
     */
    public function test_a_roster_import_never_asks_the_oracle_because_no_row_it_can_reach_has_an_account(): void
    {
        $this->seed(ReferenceSeeder::class);

        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        User::factory()->create([
            'full_name' => 'Original Holder', 'member_email' => 'account.holder@example.test', 'position' => 3,
        ]);

        $request = Request::create('/admin/roster-import/commit', 'POST');
        $request->setUserResolver(fn (): User => $admin);

        $mapping = [
            'full_name' => 'Full Name', 'short_name' => 'Short Name', 'email' => 'Email',
            'phone' => 'Phone', 'position' => 'Position', 'level' => 'Level', 'joined_at' => 'Joined',
        ];

        $fixtures = __DIR__.'/../../fixtures/roster';

        DB::enableQueryLog();
        DB::flushQueryLog();

        $created = RosterImport::commit(new CsvRosterReader($fixtures.'/clean.csv'), $mapping, [], $request);
        $skipped = RosterImport::commit(new CsvRosterReader($fixtures.'/collides-with-an-account.csv'), $mapping, [], $request);

        $oracle = array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $sql): bool => str_contains($sql, 'capabilities'),
        ));

        DB::disableQueryLog();

        $this->assertSame(7, $created['summary']['created'],
            'the import wrote nothing — a budget over an import that did not happen proves nothing');
        $this->assertSame(1, $skipped['summary']['skipped']);
        $this->assertSame(RosterImport::SKIP_HAS_ACCOUNT, $skipped['rows'][0]['outcome'],
            'the account-holder row was not skipped — the reason this cost is zero no longer holds');

        $this->assertSame([], $oracle,
            'a roster import consulted the capability oracle '.count($oracle).' times — that is the '
            .'five-query-per-row N+1 the null $couldLose short-circuit exists to prevent');
    }

    /**
     * The other half of the same measurement, and the reason the one above is a budget rather than
     * a hole: a door that DOES touch an account asks exactly once. Without this, "the import never
     * asks" would be equally satisfied by a guard that never ran anywhere.
     *
     * THE NEEDLE IS NARROWER HERE, and deliberately so. The import case can match the whole word
     * `capabilities` because a `RosterImport::commit()` call touches no capability table at all,
     * which makes the broad needle exact. A REQUEST does: `EnsureCapability` resolves the actor's
     * set through `role_capabilities`/`user_capabilities` joined onto `capabilities` before the
     * controller runs. So this counts `holdersOf()`'s own opening lookup — `select id from
     * capabilities where key = ?` — which nothing else on this route runs.
     */
    public function test_a_door_that_touches_an_account_asks_the_oracle_exactly_once(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        $target = User::factory()->create(['position' => 4, 'full_name' => 'ZZZ Resident']);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($admin)
            ->patch('/admin/users/'.$target->id.'/position', ['position' => 3])
            ->assertSessionHasNoErrors();

        $asked = count(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static function (string $sql): bool {
                // Identifier quoting is the driver's business (`"` on sqlite, backticks on
                // MySQL) and is not what this is measuring.
                return str_contains(str_replace(['"', '`'], '', $sql), 'from capabilities where key = ?');
            },
        ));

        DB::disableQueryLog();

        $this->assertSame(3, (int) $target->person->fresh()->position);
        $this->assertSame(1, $asked,
            "the position endpoint asked the oracle {$asked} times for one write");
    }

    // ------------------------------------------------------------------ the refusal itself

    /** The refusal names the remedy, in `assertNoSelfLockout()`'s style, and audits nothing. */
    public function test_the_refusal_names_the_remedy_and_writes_no_audit_row(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $auditBefore = AuditLog::count();

        $this->actingAs($admin)
            ->from('/admin/access-control')
            ->put('/admin/access-control/user', [
                'user_id' => $admin->id,
                'overrides' => [$this->capId('access.manage') => 'deny'],
            ]);

        // Read the bag the way `TestResponse::assertSessionHasErrors()` does, START INCLUDED —
        // `session('errors')` answers with the store's raw pre-start array here, and `->getBag()`
        // on that is a fatal rather than a failure (the same trap `PersonRolesTest::submit()`
        // records, measured again here).
        $session = app('session.store');

        if (! $session->isStarted()) {
            $session->start();
        }

        $message = (string) $session->get('errors')->getBag('default')->first('overrides');
        $this->assertStringContainsString('access.manage', $message);
        $this->assertStringContainsString('another active account', $message);

        // The throw unwinds the transaction, and the audit rows are written after the commit —
        // so a refused write leaves no `access_user_*` row claiming it happened.
        $this->assertSame($auditBefore, AuditLog::count());
    }
}
