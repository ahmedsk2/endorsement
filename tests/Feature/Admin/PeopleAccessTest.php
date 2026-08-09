<?php

namespace Tests\Feature\Admin;

use App\Models\Capability;
use App\Models\Person;
use App\Models\RoleCapability;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * P1c's new capability, `people.manage` — the ROSTER (who exists, what level they hold, how to
 * reach them), as opposed to `users.manage`'s ACCOUNT console and `structure.manage`'s
 * departmental shape. Administrator-only by default, grantable per role or per named user.
 *
 * The screen is PERSON-scoped where Admin → Users is ACCOUNT-scoped, and the two must not be
 * conflated: a roster-only person is invisible to Admin → Users by construction (its list is
 * `User::query()->join('people', ...)`), and that person is frequently the on-call consultant
 * whose name is frozen onto signed evidence.
 */
class PeopleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    public function test_the_capability_is_in_the_catalog(): void
    {
        $this->assertDatabaseHas('capabilities', ['key' => 'people.manage']);
    }

    public function test_it_defaults_to_administrator_only(): void
    {
        $id = (int) Capability::where('key', 'people.manage')->value('id');

        $this->assertSame(
            [0],
            RoleCapability::where('capability_id', $id)->pluck('position')->map(intval(...))->all()
        );
    }

    public function test_an_administrator_can_open_the_people_screen(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'Aisha Admin']);
        Person::factory()->create(['full_name' => 'Bilal Roster', 'position' => 3]);

        $this->actingAs($admin)->get('/admin/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/People')
                // Two people: the administrator's own person, and the roster-only consultant.
                ->has('people', 2)
                ->has('positions')
            );
    }

    /**
     * The whole reason this screen is person-scoped rather than account-scoped. Admin → Users
     * cannot show this row at all.
     */
    public function test_a_roster_only_person_is_listed(): void
    {
        // The admin's own person is named deterministically (rather than left to Faker) so it
        // sorts BEFORE the target below — the list is ordered by `full_name`, and a random name
        // that happened to sort after "Never Logged In" would move the target to index 0 and
        // flip this from a real assertion into a coin flip. Verified empirically: this test
        // passed standalone (`--filter`) but failed inside the full suite run, where the shared
        // Faker PRNG state produced a different admin name that sorted the wrong way.
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        $person = Person::factory()->create(['full_name' => 'Never Logged In', 'position' => 3]);

        $this->actingAs($admin)->get('/admin/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('people.1.full_name', 'Never Logged In')
                ->where('people.1.has_account', false)
            );

        $this->assertFalse($person->hasAccount());
    }

    /**
     * PE-01's "status" is DERIVED, never stored. Design §5.1 deviation 3: there is no
     * `person_status` column and reintroducing one recreates the twelve-defence-sites problem
     * the two-table split exists to avoid.
     */
    public function test_status_is_derived_from_active_and_the_account_join(): void
    {
        // Deterministic name, same reason as test_a_roster_only_person_is_listed above: the
        // list is sorted by `full_name`, and an unpinned Faker name is not guaranteed to sort
        // before "Departed Rotator".
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        Person::factory()->inactive()->create(['full_name' => 'Departed Rotator']);

        $this->actingAs($admin)->get('/admin/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('people.1.active', false)
                ->where('people.1.has_account', false)
            );

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('people', 'status'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('people', 'person_status'));
    }

    /** UN-04's reasoning applied to people: an administrator who cannot SEE a retired person cannot bring them back. */
    public function test_inactive_and_soft_deleted_people_are_listed(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $gone = Person::factory()->create(['full_name' => 'Soft Deleted']);
        $gone->delete();

        $this->actingAs($admin)->get('/admin/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('people', 2));
    }

    public function test_a_resident_is_refused(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/people')->assertForbidden();
    }

    /** `structure.manage` edits the department's SHAPE. A person is not a shape. */
    public function test_structure_manage_alone_does_not_open_the_roster(): void
    {
        $user = User::factory()->create(['position' => 4]);
        $this->grant($user, 'structure.manage');

        $this->actingAs($user)->get('/admin/people')->assertForbidden();
    }

    /** `users.manage` runs the ACCOUNT console; it is not a licence to read the roster's contacts. */
    public function test_users_manage_alone_does_not_open_the_roster(): void
    {
        $user = User::factory()->create(['position' => 4]);
        $this->grant($user, 'users.manage');

        $this->actingAs($user)->get('/admin/people')->assertForbidden();
    }

    public function test_a_refusal_is_audited(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/people')->assertForbidden();

        $this->assertDatabaseHas('audit_log', ['action' => 'access_denied', 'detail' => 'cap=people.manage']);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/people')->assertRedirect('/login');
    }

    private function grant(User $user, string $key): void
    {
        \App\Models\UserCapability::create([
            'user_id' => $user->getKey(),
            'capability_id' => (int) Capability::where('key', $key)->value('id'),
            'effect' => 'grant',
        ]);

        \App\Support\AccessControl::flush((int) $user->getKey());
    }
}
