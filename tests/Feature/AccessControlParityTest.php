<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AccessControl;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Day-one regression guard: after AccessControlSeeder, each role's EFFECTIVE capability set
 * must reproduce the legacy endorsement server-side gate exactly.
 *
 * Legacy gate (server side, authoritative): require_auth([0,2,3,4]) on every endorsement
 * file — Nurse (1) excluded. Admin tooling (users/access) is Administrator-only.
 * `endorsement.reopen` and `endorsement.compliance` are Administrator-only by default and
 * separately grantable (spec §8).
 */
class AccessControlParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Expected effective capability set per position, per spec §8.
     *
     * @return array<int, array<int, string>>
     */
    private function expectedByPosition(): array
    {
        $anyAuth = ['profile.manage'];
        $endorsement = ['endorsement.view', 'endorsement.edit'];
        // Reopen reverses a signed attestation (medico-legal); compliance exposes the
        // missed-days page. Both default to Administrator alone, grantable deliberately.
        $adminOnly = ['users.manage', 'access.manage', 'endorsement.reopen', 'endorsement.compliance'];

        return [
            0 => array_merge($anyAuth, $endorsement, $adminOnly), // Administrator: everything
            1 => $anyAuth,                                        // Nurse: no endorsement (legacy parity)
            2 => array_merge($anyAuth, $endorsement),             // Charge Nurse
            3 => array_merge($anyAuth, $endorsement),             // Consultant
            4 => array_merge($anyAuth, $endorsement),             // Resident
        ];
    }

    public function test_each_role_effective_set_matches_the_documented_server_gates(): void
    {
        $this->seed(AccessControlSeeder::class);

        foreach ($this->expectedByPosition() as $position => $expected) {
            $user = User::factory()->create(['position' => $position]);

            $actual = AccessControl::capabilitiesFor($user);
            sort($expected);

            $this->assertSame(
                $expected,
                $actual,
                "Effective capability set for position {$position} does not match the legacy server gate."
            );
        }
    }

    public function test_nurse_has_profile_but_no_endorsement(): void
    {
        $this->seed(AccessControlSeeder::class);
        $nurse = User::factory()->create(['position' => 1]);

        $this->assertTrue(AccessControl::allows($nurse, 'profile.manage'));
        $this->assertFalse(AccessControl::allows($nurse, 'endorsement.view'));
        $this->assertFalse(AccessControl::allows($nurse, 'endorsement.edit'));
        $this->assertFalse(AccessControl::allows($nurse, 'users.manage'));
    }

    public function test_resident_has_endorsement_edit_but_no_admin_tooling(): void
    {
        $this->seed(AccessControlSeeder::class);
        $resident = User::factory()->create(['position' => 4]);

        $this->assertTrue(AccessControl::allows($resident, 'endorsement.edit'));
        $this->assertFalse(AccessControl::allows($resident, 'access.manage'));
        $this->assertFalse(AccessControl::allows($resident, 'endorsement.reopen'));
    }

    public function test_only_admin_has_users_and_access_manage(): void
    {
        $this->seed(AccessControlSeeder::class);

        foreach ([0, 1, 2, 3, 4] as $position) {
            $user = User::factory()->create(['position' => $position]);
            $isAdmin = $position === 0;

            $this->assertSame($isAdmin, AccessControl::allows($user, 'users.manage'));
            $this->assertSame($isAdmin, AccessControl::allows($user, 'access.manage'));
            $this->assertSame($isAdmin, AccessControl::allows($user, 'endorsement.compliance'));
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(AccessControlSeeder::class);
        $this->seed(AccessControlSeeder::class);

        $admin = User::factory()->create(['position' => 0]);
        $expected = $this->expectedByPosition()[0];
        sort($expected);

        $this->assertSame($expected, AccessControl::capabilitiesFor($admin));
    }
}
