<?php

namespace Tests\Feature\Identity;

use App\Models\Level;
use App\Models\Person;
use App\Models\PersonLevel;
use App\Models\User;
use App\Support\LevelAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P1c Decision G: `App\Support\LevelAssignment` is the ONLY writer of `person_levels`. Finding 4:
 * before it existed, the table had no overlap constraint, no batch identity, no reason and no
 * author, and `Person::levelAt()` silently resolved whichever of two open-ended spans sorted last
 * — no error, no warning.
 *
 * Ordering note (see the P1c plan's Task 6 amendment): the exact-date collision check runs
 * BEFORE the same-level check, not after as the plan's own illustrative code order suggested —
 * that order is what makes both `test_a_duplicate_effective_from_is_skipped_not_upserted` (same
 * level, SAME date, twice) and `test_reassigning_the_same_level_is_a_no_op` (same level, a
 * DIFFERENT date, no existing row there) resolve to the outcome each test's own name promises.
 */
class LevelAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigning_closes_the_open_prior_span_on_the_day_before(): void
    {
        $person = Person::factory()->create();
        $r1 = Level::factory()->create(['code' => 'R1']);
        $r2 = Level::factory()->create(['code' => 'R2']);

        $this->assertSame(LevelAssignment::ASSIGNED, LevelAssignment::assign($person, $r1, '2026-07-01'));
        $this->assertSame(LevelAssignment::ASSIGNED, LevelAssignment::assign($person, $r2, '2027-07-01'));

        $r1Span = PersonLevel::where('person_id', $person->id)->where('level_id', $r1->id)->firstOrFail();
        $this->assertSame('2027-06-30', $r1Span->effective_to->format('Y-m-d'));

        // levelAt() is inclusive at both ends — the day before is still the OLD level, and the
        // new level's own first day is the new level, with no gap and no overlap.
        $this->assertSame('R1', $person->levelAt('2027-06-30')?->code);
        $this->assertSame('R2', $person->levelAt('2027-07-01')?->code);
    }

    public function test_two_open_spans_can_never_be_created_through_the_writer(): void
    {
        $person = Person::factory()->create();
        $r1 = Level::factory()->create(['code' => 'R1']);
        $r2 = Level::factory()->create(['code' => 'R2']);
        $r3 = Level::factory()->create(['code' => 'R3']);

        LevelAssignment::assign($person, $r1, '2025-07-01');
        LevelAssignment::assign($person, $r2, '2026-07-01');
        LevelAssignment::assign($person, $r3, '2027-07-01');

        $this->assertSame(
            1,
            PersonLevel::where('person_id', $person->id)->whereNull('effective_to')->count()
        );
    }

    public function test_a_duplicate_effective_from_is_skipped_not_upserted(): void
    {
        $person = Person::factory()->create();
        $r1 = Level::factory()->create(['code' => 'R1']);
        $r2 = Level::factory()->create(['code' => 'R2']);

        $this->assertSame(LevelAssignment::ASSIGNED, LevelAssignment::assign($person, $r1, '2026-01-01'));
        $this->assertSame(1, PersonLevel::where('person_id', $person->id)->count());

        // Same level, same date, twice.
        $this->assertSame(LevelAssignment::SKIPPED_EXISTING, LevelAssignment::assign($person, $r1, '2026-01-01'));
        $this->assertSame(1, PersonLevel::where('person_id', $person->id)->count());

        // The case that matters: a DIFFERENT level on an existing effective_from must not rewrite
        // what is already stored there. An upsert here would silently change what level someone
        // held on a date that may already be rendered beside a signed handover.
        $this->assertSame(LevelAssignment::SKIPPED_EXISTING, LevelAssignment::assign($person, $r2, '2026-01-01'));
        $this->assertSame(1, PersonLevel::where('person_id', $person->id)->count());
        $this->assertSame(
            $r1->id,
            (int) PersonLevel::where('person_id', $person->id)->value('level_id')
        );
    }

    public function test_a_span_that_would_overlap_a_closed_later_span_is_refused(): void
    {
        $person = Person::factory()->create();
        $r1 = Level::factory()->create(['code' => 'R1']);
        $r2 = Level::factory()->create(['code' => 'R2']);
        $r3 = Level::factory()->create(['code' => 'R3']);
        $r4 = Level::factory()->create(['code' => 'R4']);

        LevelAssignment::assign($person, $r1, '2025-07-01');
        LevelAssignment::assign($person, $r2, '2026-07-01');
        LevelAssignment::assign($person, $r3, '2027-07-01');

        $before = PersonLevel::where('person_id', $person->id)->count();

        // Behind the already-recorded R2/R3 spans — writing here would silently rewrite history.
        $outcome = LevelAssignment::assign($person, $r4, '2026-01-01');

        $this->assertSame(LevelAssignment::REFUSED_OVERLAP, $outcome);
        $this->assertSame($before, PersonLevel::where('person_id', $person->id)->count());
    }

    public function test_reassigning_the_same_level_is_a_no_op(): void
    {
        $person = Person::factory()->create();
        $r1 = Level::factory()->create(['code' => 'R1']);

        LevelAssignment::assign($person, $r1, '2026-01-01');
        $before = PersonLevel::where('person_id', $person->id)->count();

        // A different, LATER date than the current span's own effective_from, and no row exists
        // there — the level is unchanged, so this must be a no-op rather than a fresh span.
        $outcome = LevelAssignment::assign($person, $r1, '2026-06-01');

        $this->assertSame(LevelAssignment::SKIPPED_SAME_LEVEL, $outcome);
        $this->assertSame($before, PersonLevel::where('person_id', $person->id)->count());
    }

    public function test_provenance_is_stored(): void
    {
        $person = Person::factory()->create();
        $level = Level::factory()->create();
        $actor = User::factory()->create();
        $batch = (string) Str::uuid();

        LevelAssignment::assign($person, $level, '2026-01-01', [
            'batch' => $batch,
            'reason' => 'Annual promotion',
            'actor' => $actor->id,
        ]);

        $row = PersonLevel::where('person_id', $person->id)->firstOrFail();
        $this->assertSame($batch, $row->promotion_batch_id);
        $this->assertSame('Annual promotion', $row->reason);
        $this->assertSame($actor->id, $row->created_by);
    }

    public function test_created_by_survives_the_users_row_being_soft_deleted(): void
    {
        $person = Person::factory()->create();
        $level = Level::factory()->create();
        $actor = User::factory()->create();

        LevelAssignment::assign($person, $level, '2026-01-01', ['actor' => $actor->id]);

        $actor->delete();

        $row = PersonLevel::where('person_id', $person->id)->firstOrFail();
        $this->assertSame($actor->id, $row->created_by);
    }
}
