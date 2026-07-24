<?php

namespace Tests\Feature\Admin;

use App\Models\Capability;
use App\Models\RoleCapability;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A role DEFAULT must mean "the value when nothing has been decided" — never "the value we
 * re-impose over your decision".
 *
 * The failure this pins: an administrator unticks a role default in Admin → Access Control,
 * sees it take effect, and the next routine deploy re-runs the seeder and silently RE-GRANTS
 * it, with no notification and no audit entry explaining why. That is the worst failure mode a
 * permission system has.
 *
 * The seeder therefore records that a (position, capability) default has been APPLIED once
 * (`applied_role_defaults`), and never applies it again. Re-running is a genuine no-op; a
 * capability added LATER still receives its defaults on first sight.
 */
class AccessControlSeederRespectsRevocationsTest extends TestCase
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

    /** @return array<int, string> "position:capability_id" for every role grant, sorted. */
    private function roleGrantFingerprint(): array
    {
        $rows = RoleCapability::query()
            ->get(['position', 'capability_id'])
            ->map(static fn ($row): string => $row->position.':'.$row->capability_id)
            ->all();

        sort($rows);

        return $rows;
    }

    public function test_a_deliberately_revoked_role_default_is_not_restored_by_re_running_the_seeder(): void
    {
        $capabilityId = $this->capId('endorsement.edit');

        // The Charge Nurse role holds it by default...
        $this->assertDatabaseHas('role_capabilities', [
            'position' => 2,
            'capability_id' => $capabilityId,
        ]);

        // ...an administrator deliberately revokes it.
        RoleCapability::where('position', 2)->where('capability_id', $capabilityId)->delete();

        // A routine deploy re-runs the seeder.
        $this->seed(AccessControlSeeder::class);

        $this->assertDatabaseMissing('role_capabilities', [
            'position' => 2,
            'capability_id' => $capabilityId,
        ]);
    }

    public function test_a_capability_added_later_still_receives_its_defaults_on_first_sight(): void
    {
        // Simulate "this capability did not exist when the seeder last ran": remove it from the
        // catalog entirely (cascading its role grants) and forget that its defaults were applied.
        $capabilityId = $this->capId('endorsement.edit');
        DB::table('applied_role_defaults')
            ->where('capability_id', $capabilityId)->delete();
        Capability::whereKey($capabilityId)->delete();

        $this->assertDatabaseMissing('capabilities', ['key' => 'endorsement.edit']);

        $this->seed(AccessControlSeeder::class);

        $newId = $this->capId('endorsement.edit');
        $this->assertNotSame(0, $newId);

        // Its full default set (positions 0,2,3,4) is applied — old revocations elsewhere are
        // untouched because they carry their own applied-marker.
        foreach ([0, 2, 3, 4] as $position) {
            $this->assertDatabaseHas('role_capabilities', [
                'position' => $position,
                'capability_id' => $newId,
            ]);
        }
        $this->assertDatabaseMissing('role_capabilities', [
            'position' => 1,
            'capability_id' => $newId,
        ]);
    }

    public function test_re_running_the_seeder_twice_changes_nothing(): void
    {
        $before = $this->roleGrantFingerprint();
        $capabilityCount = Capability::count();

        $this->seed(AccessControlSeeder::class);
        $this->seed(AccessControlSeeder::class);

        $this->assertSame($before, $this->roleGrantFingerprint());
        $this->assertSame($capabilityCount, Capability::count());
    }

    public function test_a_hand_made_grant_beyond_the_defaults_is_never_trimmed(): void
    {
        // Resident (4) is given the compliance page by hand — not a seeded default.
        $capabilityId = $this->capId('endorsement.compliance');
        RoleCapability::create(['position' => 4, 'capability_id' => $capabilityId]);

        $this->seed(AccessControlSeeder::class);

        $this->assertDatabaseHas('role_capabilities', [
            'position' => 4,
            'capability_id' => $capabilityId,
        ]);
    }

    public function test_revoking_and_granting_a_role_default_are_each_audited_by_capability(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $editId = $this->capId('endorsement.edit');
        $complianceId = $this->capId('endorsement.compliance');

        $current = RoleCapability::where('position', 2)->pluck('capability_id')
            ->map(static fn ($id): int => (int) $id)->all();

        // Revoke endorsement.edit, grant endorsement.compliance — in one submission.
        $desired = array_values(array_diff($current, [$editId]));
        $desired[] = $complianceId;

        $this->actingAs($admin)
            ->from('/admin/access-control')
            ->put('/admin/access-control/role', [
                'position' => 2,
                'capability_ids' => $desired,
            ])
            ->assertRedirect('/admin/access-control');

        $this->assertDatabaseHas('audit_log', [
            'action' => 'access_role_revoke',
            'detail' => 'position=2;cap=endorsement.edit',
            'user_id' => $admin->getKey(),
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'access_role_grant',
            'detail' => 'position=2;cap=endorsement.compliance',
            'user_id' => $admin->getKey(),
        ]);
    }

    public function test_a_per_user_override_change_is_audited_by_capability(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $nurse = User::factory()->create(['position' => 1]);

        $reopenId = $this->capId('endorsement.reopen');
        $editId = $this->capId('endorsement.edit');

        $this->actingAs($admin)
            ->from('/admin/access-control')
            ->put('/admin/access-control/user', [
                'user_id' => $nurse->getKey(),
                'overrides' => [
                    $reopenId => 'grant',
                    $editId => 'deny',
                ],
            ])
            ->assertRedirect('/admin/access-control');

        $this->assertDatabaseHas('audit_log', [
            'action' => 'access_user_grant',
            'detail' => 'user='.$nurse->getKey().';cap=endorsement.reopen',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'access_user_deny',
            'detail' => 'user='.$nurse->getKey().';cap=endorsement.edit',
        ]);

        // Clearing the deny (back to inherit) is audited too.
        $this->actingAs($admin)
            ->from('/admin/access-control')
            ->put('/admin/access-control/user', [
                'user_id' => $nurse->getKey(),
                'overrides' => [$reopenId => 'grant'],
            ])
            ->assertRedirect('/admin/access-control');

        $this->assertDatabaseHas('audit_log', [
            'action' => 'access_user_override_clear',
            'detail' => 'user='.$nurse->getKey().';cap=endorsement.edit',
        ]);
    }
}
