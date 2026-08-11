<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\User;
use App\Models\UserCapability;
use App\Support\AccessControl;
use App\Support\CapabilityGrant;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
