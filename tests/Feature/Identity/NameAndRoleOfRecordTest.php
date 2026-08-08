<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Support\AccessControl;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * P0c Task 2 — `people` becomes the name and role of record. `users.full_name` and
 * `users.position` still physically exist on the table (Task 3 drops them), but nothing reads
 * or writes them any more: the read-through accessors on `User` win over a stale column, every
 * SQL-level consumer joins `people`, and every writer updates the person, not the account.
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

    /** The accessor reads through the person; a stale raw column on `users` is never seen again. */
    public function test_the_accessor_wins_over_a_stale_column(): void
    {
        $user = User::factory()->create(['full_name' => 'Dr Real Name', 'position' => 4]);

        DB::table('users')->where('id', $user->id)->update(['full_name' => 'STALE', 'position' => 9]);

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
