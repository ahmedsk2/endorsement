<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\Unit;
use App\Models\User;
use App\Support\AccessControl;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * AC-03 — "unbinding on turnover is an admin action preserving history" (P1c-2 Decision E,
 * owner decision 3, 2026-08-10).
 *
 * Unbinding clears `users.person_id`, deactivates the account and KEEPS it. Accounts are never
 * deleted: `users.id` is the actor FK on `audit_log` and the signer FK on a day's attestation.
 *
 * TWO THINGS MAKE THIS DANGEROUS RATHER THAN ROUTINE, and both have a case below.
 *
 *  1. `$user->full_name` and `$user->position` are READ-THROUGH ACCESSORS onto the linked
 *     `Person` (P0c). An active-but-unbound account would therefore be nameless and
 *     positionless on every screen with no error at all — so deactivate and unbind are one
 *     atomic act, and reactivating an unbound account is refused outright.
 *
 *  2. `handover_signoffs.signed_off_by_name` was added additive-and-nullable on 2026-07-27 and
 *     DELIBERATELY NOT BACKFILLED (its own migration docblock says so). The read is
 *     `$s?->signed_off_by_name ?? $s?->signedOffBy?->full_name`, and that fallback resolves
 *     through `users.person_id`. Nulling the link therefore blanks the signer's name on every
 *     pre-2026-07-27 signed handover that account signed — which is exactly the failure the
 *     freeze migration exists to prevent, reached through a different door, on medico-legal
 *     evidence. The unbind snapshots those rows first, inside the same transaction.
 */
class AccountUnbindTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);

        // 'AAA Admin' sorts first deliberately: the users console orders by `people.full_name`,
        // and a case that asserts on `users.0` while faker draws the other name is a lottery
        // (P1c-2 Task 4's amendment records that exact defect being drawn).
        $this->admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
    }

    private function resident(string $name = 'Zed Resident', array $overrides = []): User
    {
        return User::factory()->create($overrides + ['position' => 4, 'full_name' => $name]);
    }

    /**
     * A day this account signed, in the PRE-2026-07-27 shape: the FK is set and the frozen name
     * column is null, so the signer's name resolves live through `users.person_id`.
     *
     * It is CONSTRUCTED, never assumed: the freeze migration backfilled nothing, so on a
     * deployment younger than that date no such row exists and a test that merely signed a day
     * would be asserting against a row that already carries its snapshot.
     */
    private function unsnapshottedSignoff(User $signer, string $date = '2026-07-10'): HandoverSignoff
    {
        $unit = Unit::where('code', 'PICU')->firstOrFail();

        Handover::create([
            'unit_id' => $unit->id,
            'handover_date' => $date,
            'bed' => '1',
            'mrn' => 'M-00001',
            'patient_name' => 'Test Child',
        ]);

        $this->actingAs($signer)->patch('/endorsement/PICU/'.$date.'/signoff', [
            'endorsed_by_person_id' => $signer->person_id,
            'sign_off' => true,
        ])->assertRedirect();

        $signoff = HandoverSignoff::where('unit_id', $unit->id)->firstOrFail();

        // Age the row back to the pre-freeze shape. A query-builder write, so the model's own
        // save path is not what produced the state under test.
        DB::table('handover_signoffs')->where('id', $signoff->id)->update(['signed_off_by_name' => null]);

        return $signoff->refresh();
    }

    /** What the sheet actually says the signer is called — the real read path, not the column. */
    private function renderedSignerName(string $date = '2026-07-10'): ?string
    {
        $name = null;

        $this->actingAs($this->admin)->get('/endorsement/PICU/'.$date)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$name): void {
                $name = $page->toArray()['props']['signoff']['signed_off_by_name'] ?? null;
            });

        return $name;
    }

    // ------------------------------------------------------------------ the act

    public function test_unbinding_clears_the_link_and_deactivates_in_one_act(): void
    {
        $account = $this->resident();

        $this->actingAs($this->admin)
            ->patch('/admin/users/'.$account->id.'/unbind')
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $fresh = $account->fresh();

        $this->assertNull($fresh->person_id, 'the person link must be cleared');
        $this->assertFalse((bool) $fresh->active, 'an active-but-unbound account is nameless on every screen');
    }

    public function test_the_account_row_is_kept_and_never_deleted(): void
    {
        $account = $this->resident();
        $before = User::withTrashed()->count();

        $this->actingAs($this->admin)->patch('/admin/users/'.$account->id.'/unbind')->assertRedirect();

        $this->assertSame($before, User::withTrashed()->count(), 'accounts are never deleted');
        $this->assertDatabaseHas('users', ['id' => $account->id, 'deleted_at' => null]);
    }

    public function test_the_person_is_untouched(): void
    {
        $account = $this->resident();
        $personId = $account->person_id;

        $this->actingAs($this->admin)->patch('/admin/users/'.$account->id.'/unbind')->assertRedirect();

        // This is what makes unbind a DIFFERENT definition from PersonStatus::apply() rather
        // than a special case of it: a person can turn over to a new account and remain a
        // perfectly active member of the roster.
        $this->assertDatabaseHas('people', ['id' => $personId, 'active' => true, 'deleted_at' => null]);

        $this->actingAs($this->admin)->get('/admin/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('people', fn ($people) => collect($people)
                ->contains(fn ($p) => (int) $p['id'] === (int) $personId && $p['has_account'] === false)));
    }

    // ---------------------------------------------------------- the attestation

    public function test_an_unsnapshotted_signoff_keeps_its_signer_name(): void
    {
        $signer = $this->resident('Dr Alpha');
        $signoff = $this->unsnapshottedSignoff($signer);

        $this->assertNull($signoff->signed_off_by_name, 'the fixture must be the pre-freeze shape');
        $this->assertSame('Dr Alpha', $this->renderedSignerName(), 'baseline: the name resolves through the link');

        $this->actingAs($this->admin)->patch('/admin/users/'.$signer->id.'/unbind')->assertRedirect();

        $this->assertSame('Dr Alpha', $this->renderedSignerName(),
            'nulling users.person_id must not blank the signer on a signed sheet');
        $this->assertSame('Dr Alpha', $signoff->fresh()->signed_off_by_name);
    }

    public function test_a_signoff_that_already_has_a_name_is_not_rewritten(): void
    {
        $signer = $this->resident('Dr Alpha');
        $signoff = $this->unsnapshottedSignoff($signer);

        // The name as it was FROZEN — a later rename of the person must not reach it, and
        // neither must this unbind.
        DB::table('handover_signoffs')->where('id', $signoff->id)
            ->update(['signed_off_by_name' => 'Dr Alpha As Signed']);

        $signer->person->update(['full_name' => 'Dr Alpha Renamed']);

        $this->actingAs($this->admin)->patch('/admin/users/'.$signer->id.'/unbind')->assertRedirect();

        $this->assertSame('Dr Alpha As Signed', $signoff->fresh()->signed_off_by_name,
            'the snapshot writes only rows where the column is null');
    }

    public function test_the_snapshot_does_not_reach_another_accounts_signoff(): void
    {
        $signer = $this->resident('Dr Alpha');
        $other = $this->resident('Dr Beta');

        $signoff = $this->unsnapshottedSignoff($signer);
        DB::table('handover_signoffs')->where('id', $signoff->id)
            ->update(['signed_off_by_user_id' => $other->id]);

        $this->actingAs($this->admin)->patch('/admin/users/'.$signer->id.'/unbind')->assertRedirect();

        $this->assertNull($signoff->fresh()->signed_off_by_name,
            'the update is keyed on signed_off_by_user_id, not on "every null row"');
    }

    // ------------------------------------------------- what an unbound account is

    public function test_an_unbound_account_resolves_to_no_role_capabilities(): void
    {
        $account = $this->resident();

        $this->assertNotSame([], AccessControl::capabilitiesFor($account),
            'baseline: a resident holds the role defaults');

        $this->actingAs($this->admin)->patch('/admin/users/'.$account->id.'/unbind')->assertRedirect();

        // `AccessControl::resolve()` joins role_capabilities on `$user->position`, which is now
        // null, so the role-defaults join matches nothing. Only explicit overrides survive —
        // and the account is deactivated, so they cannot be exercised.
        $this->assertSame([], AccessControl::capabilitiesFor($account->fresh()));
    }

    public function test_the_capability_cache_is_flushed(): void
    {
        $account = $this->resident();

        // Warm the per-user cache BEFORE the unbind: CACHE_TTL is 600s, so without a flush the
        // stale set would answer for ten minutes.
        AccessControl::capabilitiesFor($account);

        $this->actingAs($this->admin)->patch('/admin/users/'.$account->id.'/unbind')->assertRedirect();

        $this->assertSame([], AccessControl::capabilitiesFor($account->fresh()));
    }

    public function test_an_unbound_account_keeps_its_explicit_overrides_as_history(): void
    {
        $account = $this->resident();
        $capabilityId = DB::table('capabilities')->where('key', 'people.manage')->value('id');

        DB::table('user_capabilities')->insert([
            'user_id' => $account->id,
            'capability_id' => $capabilityId,
            'effect' => 'grant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->patch('/admin/users/'.$account->id.'/unbind')->assertRedirect();

        // Deleting them would be "auto-restore on re-bind" inverted — owner decision 2 keeps
        // grants keyed to the account, and the account is dead, so they are inert history.
        $this->assertDatabaseHas('user_capabilities', ['user_id' => $account->id, 'effect' => 'grant']);
    }

    public function test_an_unbound_account_is_invisible_to_holders_of(): void
    {
        $account = $this->resident();
        $capabilityId = DB::table('capabilities')->where('key', 'people.manage')->value('id');

        DB::table('user_capabilities')->insert([
            'user_id' => $account->id,
            'capability_id' => $capabilityId,
            'effect' => 'grant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertContains($account->id,
            array_map(fn (User $u): int => (int) $u->getKey(), AccessControl::holdersOf('people.manage')),
            'baseline: an explicit grant makes this account a holder');

        $this->actingAs($this->admin)->patch('/admin/users/'.$account->id.'/unbind')->assertRedirect();

        // holdersOf() inner-joins `people`, so this falls out for free — asserted rather than
        // assumed, because "for free" is exactly what a later refactor removes without noticing.
        $this->assertNotContains($account->id,
            array_map(fn (User $u): int => (int) $u->getKey(), AccessControl::holdersOf('people.manage')));
    }

    public function test_an_unbound_account_cannot_log_in(): void
    {
        $account = $this->resident('Zed Resident', ['member_name' => 'unbound.one']);

        $this->post('/login', ['member_name' => 'unbound.one', 'password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->post('/logout');

        $this->actingAs($this->admin)->patch('/admin/users/'.$account->id.'/unbind')->assertRedirect();

        // actingAs() forces the admin authenticated for every later call in this test, so log
        // out for real or assertGuest() fails for a reason that has nothing to do with the fix.
        $this->post('/logout');

        $this->post('/login', ['member_name' => 'unbound.one', 'password' => 'password'])
            ->assertSessionHasErrors('member_name');
        $this->assertGuest();
    }

    public function test_the_account_disappears_from_the_users_console(): void
    {
        $account = $this->resident();

        $this->actingAs($this->admin)->patch('/admin/users/'.$account->id.'/unbind')->assertRedirect();

        // `index()` inner-joins `people`, so the row is gone. That is the decided behaviour, not
        // a discovered one — a dead account that cannot log in and cannot be reactivated is not
        // console clutter — and the flash text says so out loud.
        $this->actingAs($this->admin)->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('users', 1)
                ->where('users.0.id', $this->admin->id));
    }

    // ----------------------------------------------------------------- refusals

    public function test_an_unbound_account_cannot_be_reactivated(): void
    {
        $account = $this->resident();

        $this->actingAs($this->admin)->patch('/admin/users/'.$account->id.'/unbind')->assertRedirect();

        $this->actingAs($this->admin)
            ->patch('/admin/users/'.$account->id.'/active', ['active' => true])
            ->assertSessionHasErrors('active');

        $this->assertFalse((bool) $account->fresh()->active,
            'reactivating would produce a nameless, positionless live account');
        $this->assertNull($account->fresh()->person_id);
    }

    public function test_the_reactivation_refusal_names_the_remedy(): void
    {
        $account = $this->resident();

        $this->actingAs($this->admin)->patch('/admin/users/'.$account->id.'/unbind')->assertRedirect();

        $this->actingAs($this->admin)
            ->patch('/admin/users/'.$account->id.'/active', ['active' => true])
            ->assertSessionHasErrorsIn('default', ['active' => 'This account was unbound from its person and is kept only as history. Invite the person again to give them a new account.']);
    }

    public function test_unbinding_the_last_active_administrator_is_refused(): void
    {
        // The setUp admin is the only Administrator, and this is the account acting.
        $this->actingAs($this->admin)
            ->patch('/admin/users/'.$this->admin->id.'/unbind')
            ->assertSessionHasErrors();

        $this->assertNotNull($this->admin->fresh()->person_id);
        $this->assertTrue((bool) $this->admin->fresh()->active);
    }

    public function test_unbinding_an_already_unbound_account_is_refused_rather_than_crashing(): void
    {
        $account = $this->resident();

        $this->actingAs($this->admin)->patch('/admin/users/'.$account->id.'/unbind')->assertRedirect();

        $this->actingAs($this->admin)
            ->patch('/admin/users/'.$account->id.'/unbind')
            ->assertSessionHasErrors();

        $this->assertSame(1, AuditLog::where('action', 'account_unbound')->count(),
            'a second unbind is a no-op error, not a second audit row');
    }

    public function test_the_unbind_is_full_manager_only(): void
    {
        $chief = User::factory()->create(['position' => 5]);
        $account = $this->resident();

        // A Chief Resident may deactivate a resident account; unbinding is irreversible, has no
        // undo in the UI, and writes a clinical table, so it stays behind `cap:users.manage`.
        $this->actingAs($chief)
            ->patch('/admin/users/'.$account->id.'/unbind')
            ->assertForbidden();

        $this->assertNotNull($account->fresh()->person_id);
    }

    public function test_the_unbind_endpoint_refuses_a_get(): void
    {
        $account = $this->resident();

        $this->actingAs($this->admin)
            ->get('/admin/users/'.$account->id.'/unbind')
            ->assertStatus(405);
    }

    // -------------------------------------------------------------------- audit

    public function test_the_unbind_is_audited_by_ids_and_a_count(): void
    {
        $signer = $this->resident('Dr Alpha');
        $personId = $signer->person_id;
        $this->unsnapshottedSignoff($signer);

        $this->actingAs($this->admin)->patch('/admin/users/'.$signer->id.'/unbind')->assertRedirect();

        $row = AuditLog::where('action', 'account_unbound')->latest('id')->firstOrFail();
        $detail = (string) $row->detail;

        $this->assertSame('user='.$signer->id.';person='.$personId.';signoffs_snapshotted=1', $detail);
        $this->assertSame($this->admin->id, (int) $row->user_id);
        $this->assertStringNotContainsString('Alpha', $detail);
        $this->assertStringNotContainsString('@', $detail);
    }
}
