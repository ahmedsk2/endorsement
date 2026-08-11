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
        // rota.view (P1d) defaults to EVERY seeded position — MR-05's point is that a resident
        // can see which unit they rotate through next. clinics.view (P1e) joins it on the same
        // reasoning one requirement along (CL-05): a resident needs to know when their unit's
        // clinic runs. Both are READ keys; the matching write keys (rota.manage, and for clinics
        // the existing structure.manage) stay Administrator-only below.
        $anyAuth = ['profile.manage', 'rota.view', 'clinics.view'];
        $endorsement = ['endorsement.view', 'endorsement.edit'];
        // Reopen reverses a signed attestation (medico-legal); compliance exposes the
        // missed-days page; settings edits runtime config; structure.manage (P1b) edits the
        // department's shape (units/levels/calendar/periods/holidays); people.manage (P1c)
        // edits the roster (people, levels, promotion, roster import); rota.manage (P1d) edits
        // master rota assignments and vacations — Administrator-only from 2026-08-10, reversing
        // the P1d-1 default that also gave it to Chief Resident. Administrator-only defaults.
        $adminOnly = [
            'users.manage', 'users.manage_residents', 'access.manage', 'settings.manage',
            'endorsement.reopen', 'endorsement.compliance', 'structure.manage', 'people.manage',
            'rota.manage',
        ];

        return [
            0 => array_merge($anyAuth, $endorsement, $adminOnly), // Administrator: everything
            // Position 1 (Nurse) is RETIRED - no catalog row, no defaults, nothing to assert.
            2 => array_merge($anyAuth, $endorsement),             // Charge Nurse
            3 => array_merge($anyAuth, $endorsement),             // Consultant
            4 => array_merge($anyAuth, $endorsement),             // Resident
            // Chief Resident (5): a resident clinically + the one scoped admin power.
            // `rota.manage` is NOT here (owner decision 2, 2026-08-10, reversing the 2026-08-09
            // decision P1d-1 shipped): it defaults Administrator-only, so it sits in $adminOnly
            // above and a department that wants Chief Resident to hold it grants it from the
            // Access Control screen.
            5 => array_merge($anyAuth, $endorsement, ['users.manage_residents']),
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

    /** The retired Nurse position (1) resolves to NOTHING - it has no defaults at all. */
    public function test_the_retired_nurse_position_holds_no_capabilities(): void
    {
        $this->seed(AccessControlSeeder::class);
        $ghost = User::factory()->create(['position' => 1]);

        $this->assertSame([], AccessControl::capabilitiesFor($ghost));
        $this->assertFalse(AccessControl::allows($ghost, 'profile.manage'));
        $this->assertFalse(AccessControl::allows($ghost, 'endorsement.view'));
    }

    public function test_a_chief_resident_holds_the_scoped_power_but_no_admin_console(): void
    {
        $this->seed(AccessControlSeeder::class);
        $chief = User::factory()->create(['position' => 5]);

        $this->assertTrue(AccessControl::allows($chief, 'users.manage_residents'));
        $this->assertTrue(AccessControl::allows($chief, 'endorsement.edit'));
        $this->assertFalse(AccessControl::allows($chief, 'users.manage'));
        $this->assertFalse(AccessControl::allows($chief, 'access.manage'));
        $this->assertFalse(AccessControl::allows($chief, 'settings.manage'));
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

        foreach ([0, 2, 3, 4, 5] as $position) {
            $user = User::factory()->create(['position' => $position]);
            $isAdmin = $position === 0;

            $this->assertSame($isAdmin, AccessControl::allows($user, 'users.manage'));
            $this->assertSame($isAdmin, AccessControl::allows($user, 'access.manage'));
            $this->assertSame($isAdmin, AccessControl::allows($user, 'endorsement.compliance'));
            $this->assertSame($isAdmin, AccessControl::allows($user, 'settings.manage'));
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
