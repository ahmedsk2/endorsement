<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Support\AccessControl;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * P0c Task 2 — `people` is the name and role of record: the read-through accessors on `User`
 * resolve through the person, every SQL-level consumer joins `people`, and every writer updates
 * the person, not the account. Written when `users.full_name`/`users.position` still physically
 * existed (nothing read or wrote them even then); Task 3 has since dropped both columns, which
 * this file's structural test now asserts directly.
 */
class NameAndRoleOfRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    /**
     * The accessor reads through the person. Originally written (Task 2) against a still-live
     * `users.full_name`/`position` to prove the accessor wins over a stale raw column; Task 3
     * dropped both columns, which is the stronger version of the same guarantee — there is no
     * column left to go stale. Kept as a structural assertion, mirroring
     * `RosterOnlyCannotAuthenticateTest::test_the_people_table_carries_no_credential_column()`:
     * a migration that reintroduces either column on `users` turns this red on the spot.
     */
    public function test_full_name_and_position_are_not_columns_on_users(): void
    {
        foreach (['full_name', 'position'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('users', $column),
                "users.{$column} exists — the name/role of record has drifted back onto the ".
                'account table, and $user->'.$column.' would silently start reading it again.'
            );
        }

        $user = User::factory()->create(['full_name' => 'Dr Real Name', 'position' => 4]);
        $fresh = $user->fresh();

        $this->assertSame('Dr Real Name', $fresh->full_name);
        $this->assertSame(4, $fresh->position);
    }

    /** Capability resolution keys off `$user->position`, which now reads through the person. */
    public function test_capabilities_follow_the_persons_position(): void
    {
        $user = User::factory()->create(['position' => 0]);

        $this->assertTrue(AccessControl::allows($user, 'access.manage'));

        $user->person->update(['position' => 4]);
        AccessControl::flush($user->getKey());

        $this->assertFalse(AccessControl::allows($user->fresh(), 'access.manage'));
    }

    /** holdersOf() orders by the person's name and returns real `users` models, keyed on users.id. */
    public function test_holders_of_orders_by_the_persons_name_and_returns_user_models(): void
    {
        $cc = User::factory()->create(['position' => 0, 'full_name' => 'Cc']);
        $aa = User::factory()->create(['position' => 0, 'full_name' => 'Aa']);
        $bb = User::factory()->create(['position' => 0, 'full_name' => 'Bb']);

        $holders = AccessControl::holdersOf('endorsement.reopen');

        $this->assertSame(['Aa', 'Bb', 'Cc'], array_map(fn (User $u) => $u->full_name, $holders));

        // Keyed on users.id, never people.id — the two sequences are independent.
        $this->assertSame($aa->getKey(), $holders[0]->getKey());
        $this->assertContains($holders[0]->getKey(), [$aa->getKey(), $bb->getKey(), $cc->getKey()]);
    }

    /** Admin -> Users is scoped and ordered through the person. */
    public function test_admin_users_screen_is_scoped_and_ordered_through_the_person(): void
    {
        $chief = User::factory()->create(['position' => 5]);
        User::factory()->create(['position' => 4, 'full_name' => 'Zed Resident']);
        User::factory()->create(['position' => 4, 'full_name' => 'Amy Resident']);
        User::factory()->create(['position' => 3, 'full_name' => 'Consultant Not Shown']);

        $this->actingAs($chief)
            ->get('/admin/users')
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.0.full_name', 'Amy Resident')
                ->where('users.1.full_name', 'Zed Resident')
                ->where('users', fn ($users) => count($users) === 2)
            );
    }

    /** A self profile edit writes both `people` and `users` in one transaction. */
    public function test_a_self_profile_edit_writes_both_rows(): void
    {
        $user = User::factory()->create(['full_name' => 'Old Name', 'member_email' => 'old@example.org']);

        $this->actingAs($user)->patch('/profile', [
            'full_name' => 'New Name',
            'member_name' => $user->member_name,
            'member_email' => 'new@example.org',
        ])->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertSame('New Name', $fresh->person->full_name);
        $this->assertSame('new@example.org', $fresh->member_email);
        $this->assertSame('new@example.org', $fresh->person->email);
    }

    /** An admin profile edit writes both rows, same as the self-edit path. */
    public function test_an_admin_profile_edit_writes_both_rows(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $target = User::factory()->create(['full_name' => 'Old Target', 'member_email' => 'old-target@example.org']);

        $this->actingAs($admin)->patch('/admin/users/'.$target->id.'/profile', [
            'full_name' => 'New Target',
            'member_name' => $target->member_name,
            'member_email' => 'new-target@example.org',
        ])->assertSessionHasNoErrors();

        $fresh = $target->fresh();
        $this->assertSame('New Target', $fresh->person->full_name);
        $this->assertSame('new-target@example.org', $fresh->member_email);
        $this->assertSame('new-target@example.org', $fresh->person->email);
    }

    /** setPosition writes the person and busts the capability cache. */
    public function test_set_position_writes_the_person(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $target = User::factory()->create(['position' => 4]);

        $this->actingAs($admin)
            ->patch('/admin/users/'.$target->id.'/position', ['position' => 3])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $target->fresh()->person->position);
        $this->assertSame(3, $target->fresh()->position);
    }
}
