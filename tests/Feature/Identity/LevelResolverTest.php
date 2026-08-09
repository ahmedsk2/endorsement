<?php

namespace Tests\Feature\Identity;

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
 * Finding 5: `Person::levelAt()` is one query per person, so a People list of 60 showing
 * "current level" is 60 queries and a promotion preview over a cohort is the same again.
 * `Person::levelsAt()` is the set-wise sibling, sharing ONE predicate definition with
 * `levelAt()` (`inForceOn()`) — two copies of one predicate is the drift CLAUDE.md blames for the
 * audit-chain false alarm.
 *
 * Finding 7: `Level::scopeActive()` was not table-qualified while `Person::scopeActive()` was;
 * any query joining `people` and `levels` and calling `Level::active()` got an ambiguous-column
 * error. Fixed at its one source in `Level::scopeActive()`.
 */
class LevelResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_levels_at_matches_level_at_for_every_person_in_the_set(): void
    {
        $r1 = Level::factory()->create(['code' => 'R1']);
        $r2 = Level::factory()->create(['code' => 'R2']);
        $today = '2026-08-09';

        // No history at all.
        $none = Person::factory()->create();

        // One open span.
        $open = Person::factory()->create();
        PersonLevel::create(['person_id' => $open->id, 'level_id' => $r1->id, 'effective_from' => '2026-01-01', 'effective_to' => null]);

        // One closed span, still covering today.
        $closed = Person::factory()->create();
        PersonLevel::create(['person_id' => $closed->id, 'level_id' => $r1->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);

        // Two consecutive spans; today falls in the second.
        $consecutive = Person::factory()->create();
        PersonLevel::create(['person_id' => $consecutive->id, 'level_id' => $r1->id, 'effective_from' => '2025-07-01', 'effective_to' => '2026-06-30']);
        PersonLevel::create(['person_id' => $consecutive->id, 'level_id' => $r2->id, 'effective_from' => '2026-07-01', 'effective_to' => null]);

        // A span starting tomorrow — not yet in force.
        $future = Person::factory()->create();
        PersonLevel::create(['person_id' => $future->id, 'level_id' => $r1->id, 'effective_from' => '2026-08-10', 'effective_to' => null]);

        // A span that ended yesterday — no longer in force, and no successor.
        $ended = Person::factory()->create();
        PersonLevel::create(['person_id' => $ended->id, 'level_id' => $r1->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-08-08']);

        // A span starting exactly on the query date.
        $startsToday = Person::factory()->create();
        PersonLevel::create(['person_id' => $startsToday->id, 'level_id' => $r2->id, 'effective_from' => $today, 'effective_to' => null]);

        // A span ending exactly on the query date.
        $endsToday = Person::factory()->create();
        PersonLevel::create(['person_id' => $endsToday->id, 'level_id' => $r1->id, 'effective_from' => '2026-01-01', 'effective_to' => $today]);

        $people = collect([$none, $open, $closed, $consecutive, $future, $ended, $startsToday, $endsToday]);

        $resolved = Person::levelsAt($people, $today);

        foreach ($people as $person) {
            $this->assertSame(
                $person->levelAt($today)?->getKey(),
                $resolved[(int) $person->getKey()]?->getKey(),
                "levelsAt() disagreed with levelAt() for person {$person->getKey()}."
            );
        }
    }

    public function test_both_bounds_are_inclusive_in_the_set_wise_resolver(): void
    {
        $person = Person::factory()->create();
        $r1 = Level::factory()->create(['code' => 'R1']);
        $r2 = Level::factory()->create(['code' => 'R2']);

        PersonLevel::create(['person_id' => $person->id, 'level_id' => $r1->id, 'effective_from' => '2025-07-01', 'effective_to' => '2026-06-30']);
        PersonLevel::create(['person_id' => $person->id, 'level_id' => $r2->id, 'effective_from' => '2026-07-01', 'effective_to' => null]);

        $people = collect([$person]);

        $this->assertSame('R1', Person::levelsAt($people, '2026-06-30')[$person->id]?->code);
        $this->assertSame('R2', Person::levelsAt($people, '2026-07-01')[$person->id]?->code);
    }

    public function test_a_person_with_no_history_resolves_to_null_not_a_missing_key(): void
    {
        $person = Person::factory()->create();

        $resolved = Person::levelsAt(collect([$person]), '2026-08-09');

        $this->assertArrayHasKey($person->id, $resolved);
        $this->assertNull($resolved[$person->id]);
    }

    public function test_it_runs_a_constant_number_of_queries_regardless_of_set_size(): void
    {
        $level = Level::factory()->create();
        $few = Person::factory()->count(3)->create();
        $many = Person::factory()->count(30)->create();

        foreach ($few->concat($many) as $person) {
            PersonLevel::create([
                'person_id' => $person->getKey(),
                'level_id' => $level->getKey(),
                'effective_from' => '2026-01-01',
            ]);
        }

        \DB::enableQueryLog();
        Person::levelsAt($few, '2026-08-09');
        $forThree = count(\DB::getQueryLog());

        \DB::flushQueryLog();
        Person::levelsAt($many, '2026-08-09');
        $forThirty = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame($forThree, $forThirty, 'levelsAt() must not scale its query count with the set.');
        $this->assertLessThanOrEqual(2, $forThirty);
    }

    public function test_the_people_screen_shows_a_current_level_without_an_n_plus_one(): void
    {
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);

        // Not 'R3': ReferenceSeeder already seeds R1…R4 and EXT, and `levels.code` is unique.
        $level = Level::factory()->create();
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        $people = Person::factory()->count(10)->create();

        foreach ($people as $person) {
            PersonLevel::create(['person_id' => $person->id, 'level_id' => $level->id, 'effective_from' => '2026-01-01']);
        }

        \DB::enableQueryLog();
        $this->actingAs($admin)->get('/admin/people')->assertOk();
        $queryCount = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        // One levelsAt() query (plus the level eager-load) must not scale with the roster —
        // a generous ceiling that would still catch a per-person N+1 on 11 people.
        $this->assertLessThan(15, $queryCount);
    }

    public function test_level_scope_active_is_table_qualified_and_survives_a_join_with_people(): void
    {
        $active = Level::factory()->create(['code' => 'ACTIVE', 'active' => true]);
        $retired = Level::factory()->create(['code' => 'RETIRED', 'active' => false]);
        $person = Person::factory()->create();

        PersonLevel::create(['person_id' => $person->id, 'level_id' => $active->id, 'effective_from' => '2026-01-01']);
        PersonLevel::create(['person_id' => $person->id, 'level_id' => $retired->id, 'effective_from' => '2020-01-01', 'effective_to' => '2020-12-31']);

        // Both `levels` and `people` carry an `active` column (finding 7); an unqualified
        // predicate in Level::scopeActive() throws an ambiguous-column error the moment a query
        // joins both tables and calls it — exactly the shape Task 10's promotion picker builds.
        $codes = Level::query()
            ->join('person_levels', 'person_levels.level_id', '=', 'levels.id')
            ->join('people', 'people.id', '=', 'person_levels.person_id')
            ->active()
            ->pluck('levels.code')
            ->all();

        $this->assertSame(['ACTIVE'], $codes);
    }
}
