<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H / GAP 13 + GAP 14 — the two remaining admin self-service holes in Admin → Users.
 *
 * GAP 14 (legacy control.php:406): an admin edits ANOTHER user's display name / username / email.
 * Users can already self-edit at /profile, but before this an admin could not fix a typo'd account
 * without direct DB access.
 *
 * GAP 13 (legacy PICU-users-delete.php:11, gate [0]): remove a user. Legacy fired a hard
 * `DELETE FROM members`. We SOFT delete: `users.id` is the actor FK on audit_log and is referenced
 * by clinical rows, so a hard delete would orphan the accountability trail — exactly the thing an
 * audit log exists to prevent. Soft delete revokes access (the auth path only resolves non-trashed
 * users) while every historical reference keeps resolving.
 */
class UserProfileAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['position' => 0]);
    }

    // ---------------------------------------------------------------- GAP 14

    public function test_admin_corrects_another_users_name_and_email(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create([
            'full_name' => 'Ahmd Al-Qatifi',
            'member_name' => 'aalqatif',
            'member_email' => 'typo@example.test',
        ]);

        $this->actingAs($admin)
            ->patch('/admin/users/'.$user->id.'/profile', [
                'full_name' => 'Ahmed Al-Qatifi',
                'member_name' => 'aalqatifi',
                'member_email' => 'ahmed@example.test',
            ])
            ->assertRedirect();

        $user->refresh();
        $this->assertSame('Ahmed Al-Qatifi', $user->full_name);
        $this->assertSame('aalqatifi', $user->member_name);
        $this->assertSame('ahmed@example.test', $user->member_email);

        $this->assertDatabaseHas('audit_log', ['action' => 'user_profile_update', 'user_id' => $admin->id]);
    }

    public function test_an_admin_edit_cannot_collide_with_an_existing_username(): void
    {
        User::factory()->create(['member_name' => 'taken']);
        $user = User::factory()->create(['member_name' => 'mine']);

        $this->actingAs($this->admin())
            ->patch('/admin/users/'.$user->id.'/profile', [
                'full_name' => 'Someone',
                'member_name' => 'taken',
                'member_email' => 'someone@example.test',
            ])
            ->assertSessionHasErrors('member_name');
    }

    public function test_an_admin_profile_edit_cannot_change_the_role_or_password(): void
    {
        $user = User::factory()->create(['position' => 1]);
        $before = $user->password;

        $this->actingAs($this->admin())
            ->patch('/admin/users/'.$user->id.'/profile', [
                'full_name' => 'Nurse Person',
                'member_name' => 'nurse.person',
                'member_email' => 'nurse@example.test',
                'position' => 0,
                'password' => 'hijack-me',
            ]);

        $user->refresh();
        $this->assertSame(1, (int) $user->position);
        $this->assertSame($before, $user->password);
    }

    public function test_a_non_admin_cannot_edit_another_users_profile(): void
    {
        $user = User::factory()->create(['full_name' => 'Untouched']);

        $this->actingAs(User::factory()->create(['position' => 3]))
            ->patch('/admin/users/'.$user->id.'/profile', [
                'full_name' => 'Touched',
                'member_name' => 'touched',
                'member_email' => 'touched@example.test',
            ])
            ->assertForbidden();

        $this->assertSame('Untouched', $user->fresh()->full_name);
    }

    // ------------------------------------------------- deletion was REMOVED (2026-07-19)

    /**
     * Account deletion is gone, and the ENDPOINT is gone with it -- not merely the button.
     *
     * `users.id` is the actor FK on `audit_log` and is referenced from clinical rows, so
     * retiring an account by any removal mechanism degrades the trail that exists to say who did
     * what to a child's record. Deactivation achieves the operational need without that cost.
     *
     * Hiding the affordance while leaving the route reachable would be security theatre: an
     * admin session could still delete by crafting the request. This test is the guard against
     * the route quietly coming back.
     */
    public function test_the_user_delete_endpoint_no_longer_exists(): void
    {
        $user = User::factory()->create(['position' => 1]);

        $this->actingAs($this->admin())
            ->delete('/admin/users/'.$user->id)
            // 404: the URI resolves to no route at all for DELETE.
            ->assertNotFound();

        $this->assertNull($user->fresh()->deleted_at, 'the account must be untouched');
        $this->assertDatabaseMissing('audit_log', ['action' => 'user_delete']);
    }

    /** Deactivation is the supported retirement path, and it keeps the account resolvable. */
    public function test_deactivation_retires_an_account_without_losing_the_audit_trail(): void
    {
        $user = User::factory()->create(['position' => 1, 'active' => true]);

        $this->actingAs($this->admin())
            ->patch('/admin/users/'.$user->id.'/active', ['active' => false])
            ->assertRedirect();

        $this->assertFalse((bool) $user->fresh()->active);
        // Still resolvable, so every past action stays attributable to a named person.
        $this->assertNotNull(User::find($user->id));
    }
}
