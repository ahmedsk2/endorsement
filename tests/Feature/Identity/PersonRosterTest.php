<?php

namespace Tests\Feature\Identity;

use App\Models\Person;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `people` is the roster; `users` is the account. This test covers the shape of `Person` itself —
 * the structural "no account" case, the read-through from a claimed account, the two UNIQUE
 * constraints (`users.person_id`, `people.short_name`), `matchByEmail()`'s normalization, and that
 * `notes` stays plaintext (owner decision 3) — none of which any other test yet exercises.
 */
class PersonRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_roster_only_person_has_no_account(): void
    {
        $person = Person::factory()->create();

        $this->assertFalse($person->hasAccount());
        $this->assertNull($person->user);
    }

    public function test_a_claimed_account_reads_back_through_its_person(): void
    {
        $user = User::factory()->create(['full_name' => 'Dr Alpha', 'position' => 5]);

        $this->assertSame('Dr Alpha', $user->person->full_name);
        $this->assertSame(5, $user->person->position);
    }

    public function test_a_person_can_have_at_most_one_account(): void
    {
        $person = Person::factory()->create();
        User::factory()->for($person, 'person')->create();

        $this->expectException(QueryException::class);

        User::factory()->for($person, 'person')->create();
    }

    public function test_short_name_is_unique(): void
    {
        Person::factory()->create(['short_name' => 'AA']);

        $this->expectException(QueryException::class);

        Person::factory()->create(['short_name' => 'AA']);
    }

    public function test_match_by_email_normalizes_case_and_whitespace(): void
    {
        Person::factory()->create(['email' => 'dr.x@example.org']);

        $found = Person::matchByEmail(' Dr.X@Example.ORG ');

        $this->assertNotNull($found);
        $this->assertSame('dr.x@example.org', $found->email);

        $this->assertNull(Person::matchByEmail(null));
        $this->assertNull(Person::matchByEmail('  '));
    }

    public function test_match_by_email_finds_a_soft_deleted_person(): void
    {
        $person = Person::factory()->create(['email' => 'left@example.org']);
        $person->delete();

        $found = Person::matchByEmail('left@example.org');

        $this->assertNotNull($found);
        $this->assertTrue($found->trashed());
    }

    /**
     * Owner decision 3 (2026-08-08) OVERRIDES the plan's original draft, which cast this through
     * EncryptedString before the owner decided: `people.notes` stays PLAINTEXT, same as
     * `people.constraints`. This asserts the stored value round-trips unchanged and that the raw
     * column genuinely holds the plaintext — the opposite of what an earlier version of this test
     * asserted — so a future re-introduction of the cast turns this red.
     */
    public function test_notes_round_trip_as_plaintext(): void
    {
        $person = Person::factory()->create(['notes' => 'Left in June']);

        $this->assertSame('Left in June', $person->fresh()->notes);
        $this->assertSame(
            'Left in June',
            DB::table('people')->where('id', $person->id)->value('notes')
        );
    }

    public function test_constraints_round_trip_as_an_array(): void
    {
        $person = Person::factory()->create(['constraints' => ['no_nights' => true]]);

        $this->assertSame(['no_nights' => true], $person->fresh()->constraints);
    }
}
