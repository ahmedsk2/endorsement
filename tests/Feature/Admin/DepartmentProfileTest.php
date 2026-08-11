<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * P1e Decision G / Munawib ST-01's first step: the department gets a display name it can
 * change, and a code it cannot.
 *
 * Design §14 item 12 recorded `institutions.name` and `institutions.code` as env-only —
 * INSTITUTION_NAME / INSTITUTION_CODE, read by `ReferenceSeeder`, so changing either meant a
 * direct database edit outside the audit trail. This screen closes the `name` half.
 *
 * The `code` half stays closed on purpose and this file proves it three ways: the write path
 * ignores a submitted code (mass assignment is a server-side question, never a disabled
 * attribute), the screen says where the code comes from, and the seeder round trip below shows
 * why — `code` is `ReferenceSeeder`'s `firstOrNew` key, so re-coding a live institution would
 * make the next `db:seed --force` create a SECOND institution row rather than update the first,
 * and `Institution::current()` returns null the moment there are two. That is a provisioning
 * operation, not a settings change.
 */
class DepartmentProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
        $this->admin = User::factory()->create(['position' => 0]);
    }

    private function institution(): Institution
    {
        return Institution::query()->firstOrFail();
    }

    // --- Who may rename it ---------------------------------------------------------------

    public function test_a_structure_manager_can_rename_the_department_and_a_resident_cannot(): void
    {
        $this->actingAs($this->admin)
            ->patch('/admin/structure/department', ['name' => 'Paediatrics, Building B'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Paediatrics, Building B', $this->institution()->fresh()->name);

        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/structure/department')->assertForbidden();
        $this->actingAs($resident)
            ->patch('/admin/structure/department', ['name' => 'Resident Renamed It'])
            ->assertForbidden();

        $this->assertSame('Paediatrics, Building B', $this->institution()->fresh()->name);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/structure/department')->assertRedirect('/login');
    }

    // --- The code is not writable --------------------------------------------------------

    /**
     * Not "the field is disabled in the template" — a disabled attribute is a rendering
     * detail and mass assignment is a server-side question. `institutions.code` is `fillable`
     * on the model (the seeder needs it), so this asserts the WRITE PATH refuses it.
     */
    public function test_the_code_is_not_writable(): void
    {
        $before = $this->institution()->code;

        $this->actingAs($this->admin)
            ->patch('/admin/structure/department', [
                'name' => 'Still Renameable',
                'code' => 'HIJACKED',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $after = $this->institution()->fresh();

        $this->assertSame($before, $after->code);
        $this->assertSame('Still Renameable', $after->name);
        $this->assertDatabaseMissing('institutions', ['code' => 'HIJACKED']);
    }

    // --- The rename survives the deploy --------------------------------------------------

    /**
     * `ReferenceSeeder` writes `name` on CREATE ONLY, and `db:seed --force` runs on every
     * deploy. This test is what PROVES that rather than assuming it: the analogous property
     * for the unit profile columns is the entire reason
     * `2026_08_15_120002_correct_ward_clinic_owner` had to exist as its own migration.
     */
    public function test_a_rename_survives_db_seed_force(): void
    {
        $this->actingAs($this->admin)
            ->patch('/admin/structure/department', ['name' => 'The Renamed Department'])
            ->assertRedirect();

        $this->seed(ReferenceSeeder::class);

        $this->assertSame('The Renamed Department', $this->institution()->fresh()->name);
        $this->assertSame(1, Institution::query()->count());
    }

    // --- The audit trail ------------------------------------------------------------------

    /**
     * Invariant 9: ids, field names and counts only. A department's name is a
     * department-identifying string and is covered by the same rule as PHI.
     *
     * `AuditLog`'s column is `detail`, SINGULAR — a negative-only assertion against `details`
     * would be green forever against an attribute Eloquent silently resolves to null, so the
     * positive claim below sits beside the negative ones deliberately.
     */
    public function test_the_rename_is_audited_by_field_name_and_never_by_value(): void
    {
        $old = $this->institution()->name;

        $this->actingAs($this->admin)
            ->patch('/admin/structure/department', ['name' => 'Al-Noor Paediatrics'])
            ->assertRedirect();

        $row = AuditLog::query()->where('action', 'institution_profile_update')->latest('id')->first();

        $this->assertNotNull($row, 'the rename must be audited');
        $this->assertStringContainsString('fields=name', (string) $row->detail);
        $this->assertStringNotContainsString('Al-Noor', (string) $row->detail);
        $this->assertStringNotContainsString($old, (string) $row->detail);
        $this->assertSame($this->admin->getKey(), $row->user_id);
    }

    /** An unchanged submission still audits, naming no field — never a phantom rename. */
    public function test_a_submission_that_changes_nothing_names_no_field(): void
    {
        $this->actingAs($this->admin)
            ->patch('/admin/structure/department', ['name' => $this->institution()->name])
            ->assertRedirect();

        $row = AuditLog::query()->where('action', 'institution_profile_update')->latest('id')->first();

        $this->assertSame('fields=none', (string) $row->detail);
    }

    // --- The screen -----------------------------------------------------------------------

    public function test_the_screen_states_that_the_code_is_set_at_provisioning(): void
    {
        $this->actingAs($this->admin)->get('/admin/structure/department')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/DepartmentProfile')
                ->where('name', 'Qatif Central Hospital')
                ->where('code', 'QCH')
                ->has('code_note')
            );

        $note = $this->actingAs($this->admin)->get('/admin/structure/department')
            ->viewData('page')['props']['code_note'];

        $this->assertStringContainsString('provisioning', $note);
        $this->assertStringContainsString('INSTITUTION_CODE', $note);
        // The screen must not imply the code is App\Support\Instance's slug: different value,
        // different variable, different job (the backup archive name and the host scripts).
        $this->assertStringNotContainsString('INSTANCE_SLUG', $note);
    }

    // --- Validation and the D11 edge --------------------------------------------------------

    public function test_the_name_is_required_and_bounded(): void
    {
        $this->actingAs($this->admin)
            ->patch('/admin/structure/department', ['name' => '  '])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->admin)
            ->patch('/admin/structure/department', ['name' => str_repeat('x', 256)])
            ->assertSessionHasErrors('name');

        $this->assertSame('Qatif Central Hospital', $this->institution()->fresh()->name);
    }

    /**
     * D11: zero or many active institutions means there is no right answer, and guessing would
     * rename a department that is not this deployment's. The same refusal
     * `CalendarSettingsController::update()` already makes, for the same reason.
     */
    public function test_a_deployment_without_one_active_institution_is_refused_rather_than_guessed(): void
    {
        Institution::query()->create(['name' => 'Second Customer', 'code' => 'SECOND', 'active' => true]);

        $this->actingAs($this->admin)
            ->patch('/admin/structure/department', ['name' => 'Ambiguous'])
            ->assertRedirect();

        $this->assertDatabaseMissing('institutions', ['name' => 'Ambiguous']);
    }
}
