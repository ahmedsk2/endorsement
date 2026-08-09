<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Level;
use App\Models\Person;
use App\Models\PersonLevel;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Munawib LV-01 write paths: create, rename, reorder, toggle `external`, and
 * deactivate — never delete. Mirrors UnitCrudTest's shape (Task 4).
 *
 * `person_levels.level_id` is `restrictOnDelete` (LevelHistoryTest already pins the DB-level
 * guard). This screen does not expose a destroy route at all, matching UnitController's own
 * precedent — deleting a level that has ever been assigned to a person is not something a form
 * validation message can make safe, so there is nothing to validate: DELETE is refused with a
 * 405, never reaches the model, and never has a chance to become a 500.
 *
 * OWNER DECISION A (2026-08-09): there is no `terminal` flag and nothing here toggles one —
 * the plan's own Task 8 text asked for a terminal toggle and a "last active non-terminal level"
 * guard built on `Level::nextAfter()`; both were dropped when Decision A removed the column and
 * the inference (see LevelLadderTest, Task 6's amendment note). Deactivating the last active
 * internal level is therefore not specially guarded here, matching Unit's own precedent (there
 * is no "last active unit" guard either) — the administrator's call, not the system's.
 */
class LevelCrudTest extends TestCase
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
            'code' => 'R5',
            'name' => 'Resident 5',
            'display_order' => 50,
            'active' => true,
            'external' => false,
        ], $overrides);
    }

    public function test_an_administrator_can_open_the_levels_screen_in_order(): void
    {
        $this->actingAs($this->admin)->get('/admin/structure/levels')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Levels')
                ->has('levels', 5)
                ->where('levels.0.code', 'R1')
                ->where('levels.4.code', 'EXT')
                ->where('levels.4.external', true)
            );
    }

    public function test_a_resident_is_refused_the_levels_screen(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/structure/levels')->assertForbidden();
    }

    public function test_an_administrator_creates_a_level(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/levels', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('status');

        $level = Level::where('code', 'R5')->first();

        $this->assertNotNull($level);
        $this->assertSame('Resident 5', $level->name);
        $this->assertSame(50, $level->display_order);
        $this->assertFalse($level->external);
    }

    /** `levels.code` is UNIQUE outright (institution-blind by design) — a duplicate is a 422, not a 23000. */
    public function test_a_duplicate_code_is_a_validation_error_not_a_database_error(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/levels', $this->payload(['code' => 'R1']))
            ->assertSessionHasErrors('code');

        $this->assertSame(5, Level::count());
    }

    public function test_an_administrator_renames_reorders_and_flags_external(): void
    {
        $r2 = Level::where('code', 'R2')->firstOrFail();

        $this->actingAs($this->admin)
            ->patch("/admin/structure/levels/{$r2->id}", $this->payload([
                'code' => 'R2',
                'name' => 'PGY-2',
                'display_order' => 25,
                'external' => true,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $r2->refresh();
        $this->assertSame('PGY-2', $r2->name);
        $this->assertSame(25, $r2->display_order);
        $this->assertTrue($r2->external);
    }

    /** A level keeps its own code on update — the unique rule must ignore itself. */
    public function test_updating_a_level_without_changing_its_code_is_allowed(): void
    {
        $r1 = Level::where('code', 'R1')->firstOrFail();

        $this->actingAs($this->admin)
            ->patch("/admin/structure/levels/{$r1->id}", $this->payload([
                'code' => 'R1', 'name' => 'Resident 1 (renamed)',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Resident 1 (renamed)', $r1->fresh()->name);
    }

    public function test_an_out_of_range_display_order_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/levels', $this->payload(['display_order' => 0]))
            ->assertSessionHasErrors('display_order');

        $this->assertSame(5, Level::count());
    }

    /**
     * LV-01/LV-04. Deactivation hides FORWARD: the level leaves a future promotion picker, and
     * every `person_levels` row it already owns is untouched.
     */
    public function test_deactivation_hides_forward_and_deletes_nothing(): void
    {
        $r1 = Level::where('code', 'R1')->firstOrFail();
        $person = Person::factory()->create();
        PersonLevel::create([
            'person_id' => $person->id,
            'level_id' => $r1->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $before = PersonLevel::where('level_id', $r1->id)->count();

        $this->actingAs($this->admin)
            ->patch("/admin/structure/levels/{$r1->id}/active", ['active' => false])
            ->assertRedirect();

        $this->assertFalse($r1->fresh()->active);
        $this->assertSame($before, PersonLevel::where('level_id', $r1->id)->count());
    }

    public function test_a_retired_level_can_be_brought_back(): void
    {
        $r1 = Level::where('code', 'R1')->firstOrFail();
        $r1->update(['active' => false]);

        $this->actingAs($this->admin)
            ->patch("/admin/structure/levels/{$r1->id}/active", ['active' => true])
            ->assertRedirect();

        $this->assertTrue($r1->fresh()->active);
    }

    /**
     * `person_levels.level_id` is `restrictOnDelete`. There is no destroy route to refuse —
     * DELETE against the same URI other verbs are registered on is a 405, never a 500,
     * regardless of whether the level has history.
     */
    public function test_there_is_no_delete_endpoint(): void
    {
        $r1 = Level::where('code', 'R1')->firstOrFail();

        $this->actingAs($this->admin)
            ->delete("/admin/structure/levels/{$r1->id}")
            ->assertStatus(405);
    }

    public function test_every_write_is_audited_by_id_and_field_never_by_value(): void
    {
        $this->actingAs($this->admin)->post('/admin/structure/levels', $this->payload());

        $row = AuditLog::where('action', 'level_create')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertStringContainsString('code=R5', $row->detail);
        $this->assertStringNotContainsString('Resident 5', $row->detail);

        $r1 = Level::where('code', 'R1')->firstOrFail();
        $this->actingAs($this->admin)->patch("/admin/structure/levels/{$r1->id}", $this->payload([
            'code' => 'R1', 'name' => 'Renamed Level',
        ]));

        $update = AuditLog::where('action', 'level_update')->latest('id')->first();

        $this->assertStringContainsString('level='.$r1->id, $update->detail);
        $this->assertStringContainsString('fields=', $update->detail);
        $this->assertStringNotContainsString('Renamed Level', $update->detail);
    }

    public function test_a_resident_cannot_write(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)
            ->post('/admin/structure/levels', $this->payload())
            ->assertForbidden();
    }
}
