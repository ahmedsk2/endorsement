<?php

namespace Tests\Feature\Admin;

use App\Models\Level;
use App\Models\Person;
use App\Models\User;
use App\Support\LevelAssignment;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * LV-04: "Level changes are effective-dated; history renders with the level held at the time."
 * Dual-dated per UX-04/AR-08: every date the server sends is a `Calendar::label()` shape
 * (Gregorian + Hijri) — the client performs no date arithmetic at all, enforced GLOBALLY by the
 * standing `tests/Feature/Build/CalendarIsTheOnlyConverterTest.php` (this file adds no needle of
 * its own; that guard already scans `resources/js/`).
 */
class LevelHistoryScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    public function test_history_renders_newest_first_dual_dated(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        $person = Person::factory()->create();
        $r1 = Level::factory()->create(['code' => 'H1']);
        $r2 = Level::factory()->create(['code' => 'H2']);

        LevelAssignment::assign($person, $r1, '2025-07-01');
        LevelAssignment::assign($person, $r2, '2026-07-01');

        $this->actingAs($admin)->get("/admin/people/{$person->id}/history")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/People')
                ->has('history.spans', 2)
                ->where('history.spans.0.level.code', 'H2')
                ->where('history.spans.0.from.date', '2026-07-01')
                ->where('history.spans.1.level.code', 'H1')
                ->where('history.spans.1.from.date', '2025-07-01')
                ->where('history.spans.1.to.date', '2026-06-30')
                ->has('history.spans.0.from.hijri')
            );
    }

    public function test_an_open_span_renders_with_to_null(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        $person = Person::factory()->create();
        $r1 = Level::factory()->create(['code' => 'H1']);

        LevelAssignment::assign($person, $r1, '2026-01-01');

        $this->actingAs($admin)->get("/admin/people/{$person->id}/history")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('history.spans.0.to', null));
    }

    /** The level held AT THE TIME, from the joined row — never a re-lookup of "current". */
    public function test_the_level_held_at_the_time_is_not_the_current_level(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        $person = Person::factory()->create();
        $r1 = Level::factory()->create(['code' => 'H1']);
        $r2 = Level::factory()->create(['code' => 'H2']);

        LevelAssignment::assign($person, $r1, '2025-07-01');
        LevelAssignment::assign($person, $r2, '2026-07-01');

        $this->assertSame('H2', $person->levelAt()?->code);

        $this->actingAs($admin)->get("/admin/people/{$person->id}/history")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('history.spans.1.level.code', 'H1'));
    }

    public function test_a_person_with_no_history_renders_an_empty_array_not_a_missing_key(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        $person = Person::factory()->create();

        $this->actingAs($admin)->get("/admin/people/{$person->id}/history")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('history.spans', 0));
    }

    /**
     * The P0c read-through-accessor trap: `with('createdBy:id,full_name')` would silently return
     * null for the actor's name, because `full_name` is not a real column on `users` — it
     * resolves through `person_id`. This is the case that would have caught it: it fails red the
     * moment the eager load omits `person_id`.
     */
    public function test_the_actor_column_carries_person_id_through_the_narrowed_query(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        $actor = User::factory()->create(['full_name' => 'Batch Author']);
        $person = Person::factory()->create();
        $level = Level::factory()->create();

        LevelAssignment::assign($person, $level, '2026-01-01', ['actor' => $actor->id]);

        $this->actingAs($admin)->get("/admin/people/{$person->id}/history")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('history.spans.0.by', 'Batch Author'));
    }

    public function test_history_is_gated_by_people_manage(): void
    {
        $resident = User::factory()->create(['position' => 4]);
        $person = Person::factory()->create();

        $this->actingAs($resident)->get("/admin/people/{$person->id}/history")->assertForbidden();
    }

    /**
     * UN-04's reasoning, applied here too: an administrator who cannot see a retired person's
     * history cannot verify what they held before leaving. `index()` already lists retired people
     * with `withTrashed()`; the history route must not 404 the moment a person is soft-deleted.
     */
    public function test_a_retired_persons_history_is_still_reachable(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        $person = Person::factory()->create();
        $level = Level::factory()->create();
        LevelAssignment::assign($person, $level, '2026-01-01');
        $person->delete();

        $this->actingAs($admin)->get("/admin/people/{$person->id}/history")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('history.spans', 1));
    }
}
