<?php

namespace Tests\Feature\Identity;

use App\Models\Level;
use App\Models\Person;
use App\Models\PersonLevel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Munawib LV-01 (levels) / LV-04 (effective-dated history). There is deliberately no
 * `people.level_id`: `Person::levelAt()` is the ONLY answer to "what level was this person on
 * date X", resolved from `person_levels` history rather than a denormalized pointer that could
 * drift from it.
 */
class LevelHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_person_with_no_history_has_no_level_on_any_date(): void
    {
        $person = Person::factory()->create();

        $this->assertNull($person->levelAt('2026-03-01'));
    }

    public function test_level_at_resolves_the_level_in_force_on_a_date(): void
    {
        $person = Person::factory()->create();
        $r1 = Level::factory()->create(['code' => 'R1']);
        $r2 = Level::factory()->create(['code' => 'R2']);

        PersonLevel::create([
            'person_id' => $person->id,
            'level_id' => $r1->id,
            'effective_from' => '2025-07-01',
            'effective_to' => '2026-06-30',
        ]);
        PersonLevel::create([
            'person_id' => $person->id,
            'level_id' => $r2->id,
            'effective_from' => '2026-07-01',
            'effective_to' => null,
        ]);

        $this->assertSame('R1', $person->levelAt('2026-01-15')?->code);
        $this->assertSame('R2', $person->levelAt('2026-08-08')?->code);
        $this->assertNull($person->levelAt('2025-01-01'));
    }

    public function test_level_at_with_no_argument_uses_today(): void
    {
        $person = Person::factory()->create();
        $current = Level::factory()->create(['code' => 'CUR']);

        PersonLevel::create([
            'person_id' => $person->id,
            'level_id' => $current->id,
            'effective_from' => today()->subYear()->toDateString(),
            'effective_to' => null,
        ]);

        $this->assertSame('CUR', $person->levelAt()?->code);
    }

    public function test_current_level_equals_level_at_today(): void
    {
        $person = Person::factory()->create();
        $level = Level::factory()->create(['code' => 'NOW']);

        PersonLevel::create([
            'person_id' => $person->id,
            'level_id' => $level->id,
            'effective_from' => today()->subMonth()->toDateString(),
            'effective_to' => null,
        ]);

        $this->assertNotNull($person->currentLevel());
        $this->assertSame($person->levelAt()?->id, $person->currentLevel()?->id);
    }

    public function test_boundary_days_are_inclusive_at_both_ends(): void
    {
        $person = Person::factory()->create();
        $r1 = Level::factory()->create(['code' => 'R1']);
        $r2 = Level::factory()->create(['code' => 'R2']);

        PersonLevel::create([
            'person_id' => $person->id,
            'level_id' => $r1->id,
            'effective_from' => '2025-07-01',
            'effective_to' => '2026-06-30',
        ]);
        PersonLevel::create([
            'person_id' => $person->id,
            'level_id' => $r2->id,
            'effective_from' => '2026-07-01',
            'effective_to' => null,
        ]);

        $this->assertSame('R1', $person->levelAt('2026-06-30')?->code);
        $this->assertSame('R2', $person->levelAt('2026-07-01')?->code);
    }

    public function test_person_id_effective_from_is_unique(): void
    {
        $person = Person::factory()->create();
        $level = Level::factory()->create();

        PersonLevel::create([
            'person_id' => $person->id,
            'level_id' => $level->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $this->expectException(QueryException::class);

        PersonLevel::create([
            'person_id' => $person->id,
            'level_id' => $level->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }

    public function test_a_level_with_history_cannot_be_deleted(): void
    {
        $person = Person::factory()->create();
        $level = Level::factory()->create();

        PersonLevel::create([
            'person_id' => $person->id,
            'level_id' => $level->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $this->expectException(QueryException::class);

        $level->delete();
    }

    public function test_deactivating_a_level_with_history_is_supported_and_history_still_resolves(): void
    {
        $person = Person::factory()->create();
        $level = Level::factory()->create(['code' => 'RET']);

        PersonLevel::create([
            'person_id' => $person->id,
            'level_id' => $level->id,
            'effective_from' => today()->subMonth()->toDateString(),
            'effective_to' => null,
        ]);

        $level->update(['active' => false]);

        $this->assertSame('RET', $person->levelAt()?->code);
    }

    public function test_level_code_is_unique(): void
    {
        Level::factory()->create(['code' => 'DUP']);

        $this->expectException(QueryException::class);

        Level::factory()->create(['code' => 'DUP']);
    }
}
