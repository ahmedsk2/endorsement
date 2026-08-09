<?php

namespace Tests\Feature\Rota;

use App\Models\Capability;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Munawib MR-02/MR-05. Two capabilities, deliberately separate:
 *
 *  - `rota.view` — READ the master rota. Defaults to EVERY seeded position, because MR-05's
 *    whole point is that residents read it. Position 1 (Nurse) is RETIRED and gets no defaults,
 *    ever.
 *  - `rota.manage` — EDIT it. Defaults to Administrator AND Chief Resident (owner decision 1,
 *    2026-08-09 — Chief Resident is Munawib's Scheduler persona and owns the master rota). This
 *    supersedes this task's own original text, which the top-of-plan OWNER DECISIONS block
 *    explicitly says is binding over stale task prose below it.
 *
 * D7: both routes sit behind `auth`. There is no anonymous route anywhere in this platform and
 * this plan adds none.
 */
class RotaAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    public function test_both_capabilities_are_in_the_catalog(): void
    {
        $this->assertNotNull(Capability::where('key', 'rota.view')->first());
        $this->assertNotNull(Capability::where('key', 'rota.manage')->first());
    }

    public function test_the_catalog_document_lists_both_keys(): void
    {
        $doc = file_get_contents(base_path('docs/spec/08-foundation.md'));

        $this->assertStringContainsString('`rota.view`', $doc);
        $this->assertStringContainsString('`rota.manage`', $doc);
    }

    public function test_an_administrator_reaches_the_editor(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)->get('/admin/rota')->assertOk();
    }

    public function test_a_chief_resident_also_reaches_the_editor(): void
    {
        // Owner decision 1: rota.manage defaults to Administrator AND Chief Resident.
        $chief = User::factory()->create(['position' => 5]);

        $this->actingAs($chief)->get('/admin/rota')->assertOk();
    }

    public function test_a_resident_is_refused_the_editor(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/rota')->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/rota')->assertRedirect('/login');
    }

    public function test_every_seeded_position_holds_rota_view_by_default(): void
    {
        foreach ([0, 2, 3, 4, 5] as $position) {
            $user = User::factory()->create(['position' => $position]);

            $this->assertTrue(
                \App\Support\AccessControl::allows($user, 'rota.view'),
                "position {$position} should hold rota.view"
            );
        }
    }

    public function test_only_an_administrator_and_chief_resident_hold_rota_manage_by_default(): void
    {
        foreach ([2, 3, 4] as $position) {
            $user = User::factory()->create(['position' => $position]);

            $this->assertFalse(
                \App\Support\AccessControl::allows($user, 'rota.manage'),
                "position {$position} must not hold rota.manage by default"
            );
        }

        $this->assertTrue(
            \App\Support\AccessControl::allows(User::factory()->create(['position' => 0]), 'rota.manage')
        );

        // Owner decision 1: Chief Resident is Munawib's Scheduler persona and owns the master
        // rota — this is a DEFAULT grant, not merely "grantable from Access Control".
        $this->assertTrue(
            \App\Support\AccessControl::allows(User::factory()->create(['position' => 5]), 'rota.manage')
        );
    }

    public function test_the_retired_nurse_position_gains_no_default(): void
    {
        $nurse = User::factory()->create(['position' => 1]);

        $this->assertFalse(\App\Support\AccessControl::allows($nurse, 'rota.view'));
        $this->assertFalse(\App\Support\AccessControl::allows($nurse, 'rota.manage'));
    }

    public function test_the_editor_route_is_not_under_the_endorsement_prefix(): void
    {
        // Unit::RESERVED_CODES is derived from routes under `endorsement/` by
        // ReservedUnitCodesTest, bidirectionally. A rota route under that prefix would demand a
        // matching reserved code in the same commit; this one deliberately avoids the question.
        $this->assertNotContains('ROTA', \App\Models\Unit::RESERVED_CODES);
    }
}
