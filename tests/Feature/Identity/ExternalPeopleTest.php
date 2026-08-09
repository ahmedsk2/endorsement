<?php

namespace Tests\Feature\Identity;

use App\Models\Person;
use App\Models\User;
use App\Support\SignoffPickers;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Munawib PE-03: an external rotator is flagged EVERYWHERE they are named — finding 3 found no
 * writer had ever set `people.external` true and no picker had ever surfaced it. Task 4 gave the
 * column a writer (`PositionChange`/`PersonController`); this task makes the flag visible.
 *
 * `external` is a LABEL, not a permission — D9's write-side boundary is position + account +
 * `active`, full stop. `PickerParityTest`'s matrix is the guard that proves surfacing the flag
 * did not quietly become a second predicate: everything offered here must still validate there.
 */
class ExternalPeopleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    public function test_an_external_person_is_flagged_in_the_signoff_picker(): void
    {
        $person = Person::factory()->create(['position' => 3, 'external' => true]);

        $offer = SignoffPickers::offer(SignoffPickers::consultantPredicate());
        $row = collect($offer)->firstWhere('id', $person->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['external']);
    }

    public function test_a_non_external_person_carries_no_external_key(): void
    {
        $person = Person::factory()->create(['position' => 3, 'external' => false]);

        $offer = SignoffPickers::offer(SignoffPickers::consultantPredicate());
        $row = collect($offer)->firstWhere('id', $person->id);

        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('external', $row);
    }

    public function test_external_does_not_change_who_may_be_named(): void
    {
        $external = Person::factory()->create(['position' => 3, 'external' => true]);
        $internal = Person::factory()->create(['position' => 3, 'external' => false]);

        $offer = SignoffPickers::offer(SignoffPickers::consultantPredicate());
        $ids = collect($offer)->pluck('id')->all();

        $this->assertContains($external->id, $ids);
        $this->assertContains($internal->id, $ids);
    }

    public function test_an_external_endorser_is_still_refused_without_an_account(): void
    {
        $person = Person::factory()->create(['position' => 4, 'external' => true]);

        $offer = SignoffPickers::offer(SignoffPickers::endorserPredicate());
        $ids = collect($offer)->pluck('id')->all();

        $this->assertNotContains($person->id, $ids);

        // The write-side rule agrees with the offer — D9's parity, exercised directly here too.
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['endorsed_by_person_id' => $person->id],
            ['endorsed_by_person_id' => SignoffPickers::rule(SignoffPickers::endorserPredicate())],
        );
        $this->assertTrue($validator->fails());
    }

    public function test_an_external_endorser_with_a_claimed_account_is_offered_and_flagged(): void
    {
        $user = User::factory()->create(['position' => 4, 'external' => true]);

        $offer = SignoffPickers::offer(SignoffPickers::endorserPredicate());
        $row = collect($offer)->firstWhere('id', $user->person_id);

        $this->assertNotNull($row);
        $this->assertTrue($row['external']);
    }

    public function test_a_retired_but_kept_external_person_still_carries_the_flag(): void
    {
        $person = Person::factory()->create(['position' => 3, 'external' => true]);
        $person->update(['active' => false]);

        $offer = SignoffPickers::offer(SignoffPickers::consultantPredicate(), [$person->id]);
        $row = collect($offer)->firstWhere('id', $person->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['retired']);
        $this->assertTrue($row['external']);
    }
}
