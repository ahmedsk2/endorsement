<?php

namespace Tests\Feature\Admin;

use App\Models\Capability;
use App\Models\RoleCapability;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * P1b's new capability, `structure.manage` — the department's SHAPE (units, levels, calendar,
 * periods, holidays), as opposed to `settings.manage`'s infrastructure (SMTP, VAPID, reminder
 * times). Administrator-only by default, grantable per role or per named user like every other
 * key.
 */
class StructureAccessTest extends TestCase
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
        $this->assertDatabaseHas('capabilities', ['key' => 'structure.manage']);
    }

    public function test_it_defaults_to_administrator_only(): void
    {
        $id = (int) Capability::where('key', 'structure.manage')->value('id');

        $this->assertSame(
            [0],
            RoleCapability::where('capability_id', $id)->pluck('position')->map(intval(...))->all()
        );
    }

    public function test_an_administrator_can_open_the_units_screen(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)->get('/admin/structure/units')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Units')
                ->has('units', 4)
                ->has('palette')
                ->where('units.0.code', 'PICU')
                ->where('units.0.training_rotation', true)
                ->where('units.0.clinic_owner', false)
                ->where('reserved_codes', ['TODAY', 'COMPLIANCE', 'ROWS'])
            );
    }

    public function test_a_resident_is_refused(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/structure/units')->assertForbidden();
    }

    /** A refusal is audited by the cap: middleware, as every other capability's is. */
    public function test_a_refusal_is_audited(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/structure/units')->assertForbidden();

        $this->assertDatabaseHas('audit_log', ['action' => 'access_denied']);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/structure/units')->assertRedirect('/login');
    }

    /**
     * The screen lists INACTIVE units too — UN-04 deactivation "hides forward", and an
     * administrator who cannot see a retired unit cannot bring it back.
     */
    public function test_inactive_units_are_listed(): void
    {
        \App\Models\Unit::create(['code' => 'RETIRED', 'name' => 'Old Ward']);
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)->get('/admin/structure/units')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('units', 5));
    }
}
