<?php

namespace Tests\Feature\Admin;

use App\Models\Handover;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Munawib UN-01…05 write paths.
 *
 * The RESERVED-CODE case is the one this screen was foreseen for: `Unit::booted()`'s own
 * docblock says "when [a UI] lands, it must surface this as a validation message rather than
 * let this exception reach the user raw". A 500 here would be the model guard doing its job
 * badly, not the request being rejected well.
 */
class UnitCrudTest extends TestCase
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'RGH1',
            'name' => 'Riyadh General Ward 1',
            'name2' => null,
            'display_order' => 5,
            'active' => true,
            'training_rotation' => true,
            'call_target' => false,
            'clinic_owner' => false,
            'aliases' => ['Ward One'],
            'bar_class' => 'channel-bar-amber',
        ], $overrides);
    }

    public function test_an_administrator_creates_a_unit(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/units', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('status');

        $unit = Unit::findByCode('RGH1');

        $this->assertNotNull($unit);
        $this->assertSame('Riyadh General Ward 1', $unit->name);
        $this->assertTrue($unit->training_rotation);
        $this->assertFalse($unit->call_target);
        $this->assertSame(['Ward One'], $unit->aliases);
        $this->assertSame('channel-bar-amber', $unit->bar_class);
    }

    /** The code mutator normalises on write; the screen must not have to know that. */
    public function test_a_lower_case_code_is_stored_normalised(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/units', $this->payload(['code' => '  rgh1 ']))
            ->assertRedirect();

        $this->assertDatabaseHas('units', ['code' => 'RGH1']);
    }

    /**
     * Finding 9: uniqueness is checked against the NORMALISED code. Validating the raw input
     * would let `picu` pass and then collide on insert with a 23000.
     */
    public function test_a_duplicate_code_is_a_validation_error_not_a_database_error(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/units', $this->payload(['code' => 'picu']))
            ->assertSessionHasErrors('code');

        $this->assertSame(4, Unit::count());
    }

    public function test_a_reserved_code_is_refused_as_a_validation_message(): void
    {
        foreach (['TODAY', 'compliance', ' Rows '] as $code) {
            $this->actingAs($this->admin)
                ->post('/admin/structure/units', $this->payload(['code' => $code]))
                ->assertSessionHasErrors('code');
        }

        $this->assertSame(4, Unit::count());
    }

    /**
     * `Unit::booted()`'s own docblock: a reserved code must surface as a validation message,
     * not the model guard's raw `InvalidArgumentException` (a 500). Proved here at the wire
     * level — an XHR/Inertia POST that fails validation gets 422, never 500 — not merely by
     * the redirect-with-session-errors shape `assertSessionHasErrors` exercises above.
     */
    public function test_a_reserved_code_is_refused_with_an_http_422_not_a_500(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
            ->post('/admin/structure/units', $this->payload(['code' => 'TODAY']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('code');
        $this->assertSame(4, Unit::count());
    }

    public function test_an_unknown_palette_class_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/units', $this->payload(['bar_class' => 'channel-bar-neon']))
            ->assertSessionHasErrors('bar_class');
    }

    public function test_an_administrator_renames_recolours_and_reorders(): void
    {
        $scbu = Unit::findByCode('SCBU');

        $this->actingAs($this->admin)
            ->patch("/admin/structure/units/{$scbu->id}", $this->payload([
                'code' => 'SCBU',
                'name' => 'Special Care Nursery',
                'name2' => 'حضانة العناية الخاصة',
                'display_order' => 2,
                'bar_class' => 'channel-bar-moss',
                'training_rotation' => true,
                'call_target' => true,
                'aliases' => ['SCN', 'Special Care Baby Unit'],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $scbu->refresh();
        $this->assertSame('Special Care Nursery', $scbu->name);
        $this->assertSame('حضانة العناية الخاصة', $scbu->name2);
        $this->assertSame(2, $scbu->display_order);
        $this->assertSame('channel-bar-moss', $scbu->bar_class);
        $this->assertSame(['SCN', 'Special Care Baby Unit'], $scbu->aliases);
    }

    /** A unit keeps its own code on update — the unique rule must ignore itself. */
    public function test_updating_a_unit_without_changing_its_code_is_allowed(): void
    {
        $picu = Unit::findByCode('PICU');

        $this->actingAs($this->admin)
            ->patch("/admin/structure/units/{$picu->id}", $this->payload([
                'code' => 'PICU', 'name' => 'PICU (renamed)',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('PICU (renamed)', $picu->fresh()->name);
    }

    /**
     * UN-04. Deactivation hides FORWARD: the unit leaves the nav and its routes 404, and every
     * clinical row it already owns is untouched. There is no delete endpoint at all.
     */
    public function test_deactivation_hides_forward_and_deletes_nothing(): void
    {
        $ward = Unit::findByCode('WARD');
        // There is no HandoverFactory in this codebase — every test builds handovers with
        // Handover::create(), matching MissedDaysTest:39. Do not introduce one for this.
        Handover::create([
            'unit_id' => $ward->id,
            'handover_date' => '2026-08-08',
            'mrn' => 'M-12345',
        ]);
        $before = Handover::where('unit_id', $ward->id)->count();

        $this->actingAs($this->admin)
            ->patch("/admin/structure/units/{$ward->id}/active", ['active' => false])
            ->assertRedirect();

        $this->assertFalse($ward->fresh()->active);
        $this->assertSame($before, Handover::where('unit_id', $ward->id)->count());
        $this->actingAs($this->admin)->get('/endorsement/ward')->assertNotFound();
    }

    public function test_there_is_no_delete_endpoint(): void
    {
        $ward = Unit::findByCode('WARD');

        $this->actingAs($this->admin)
            ->delete("/admin/structure/units/{$ward->id}")
            ->assertStatus(405);
    }

    public function test_a_retired_unit_can_be_brought_back(): void
    {
        $ward = Unit::findByCode('WARD');
        $ward->update(['active' => false]);

        $this->actingAs($this->admin)
            ->patch("/admin/structure/units/{$ward->id}/active", ['active' => true])
            ->assertRedirect();

        $this->assertTrue($ward->fresh()->active);
    }

    public function test_every_write_is_audited_by_id_and_field_never_by_value(): void
    {
        $this->actingAs($this->admin)->post('/admin/structure/units', $this->payload());

        $row = \App\Models\AuditLog::where('action', 'unit_create')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertStringContainsString('code=RGH1', $row->detail);
        $this->assertStringNotContainsString('Riyadh General Ward 1', $row->detail);

        $picu = Unit::findByCode('PICU');
        $this->actingAs($this->admin)->patch("/admin/structure/units/{$picu->id}", $this->payload([
            'code' => 'PICU', 'name' => 'Renamed', 'bar_class' => 'channel-bar-picu',
        ]));

        $update = \App\Models\AuditLog::where('action', 'unit_update')->latest('id')->first();

        $this->assertStringContainsString('unit='.$picu->id, $update->detail);
        $this->assertStringContainsString('fields=', $update->detail);
        $this->assertStringNotContainsString('Renamed', $update->detail);
    }

    public function test_a_resident_cannot_write(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)
            ->post('/admin/structure/units', $this->payload())
            ->assertForbidden();
    }
}
