<?php

namespace Tests\Feature\Identity;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The `handover_signoffs` backfill (2026_08_10_120004) runs INSIDE the migration, against
 * whatever the database looked like when it ran — so by the time `RefreshDatabase` gives this
 * test an empty database, the historical backfill has already happened and there is nothing left
 * to assert on. What this test proves instead is the RULE: that re-running the same statements
 * the migration ships resolves each `*_person_id` through `users.person_id`, never by copying the
 * `*_user_id` integer across.
 */
class SignoffPersonBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const FIELDS = ['endorsed_by', 'endorsed_to', 'consultant_by', 'consultant_to'];

    /** The exact statements 2026_08_10_120004_add_person_ids_to_handover_signoffs.php::up() runs. */
    private function runBackfill(): void
    {
        foreach (self::FIELDS as $field) {
            DB::statement(
                "update handover_signoffs set {$field}_person_id = ".
                "(select users.person_id from users where users.id = handover_signoffs.{$field}_user_id) ".
                "where {$field}_user_id is not null"
            );
        }
    }

    /**
     * The whole reason this is a join and not a copy: `people.id` and `users.id` are independent
     * sequences, so the integer 3 (say) names two different humans. A backfill written as
     * `SET endorsed_by_person_id = endorsed_by_user_id` would rename every clinician on every
     * imported sheet and raise no error at all — no FK violation, because both integers are valid
     * primary keys in their own table.
     */
    public function test_the_backfill_follows_the_link_and_not_the_integer(): void
    {
        // Force the sequences apart: three people with no account exist first, so the account
        // created next is guaranteed a users.id that differs from its people.id.
        Person::factory()->count(3)->create();
        $endorser = User::factory()->create(['full_name' => 'Dr Endorser', 'position' => 4]);

        $this->assertNotSame($endorser->id, $endorser->person_id, 'fixture: ids must diverge');

        DB::table('handover_signoffs')->insert([
            'handover_date' => '2026-01-01',
            'endorsed_by_user_id' => $endorser->id,
            'endorsed_by_name' => 'Dr Endorser',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runBackfill();

        $signoff = DB::table('handover_signoffs')->first();

        $this->assertSame($endorser->person_id, (int) $signoff->endorsed_by_person_id);
        $this->assertNotSame($endorser->id, (int) $signoff->endorsed_by_person_id);
    }

    /** A NULL user id must not resolve to person 1 by accident of the correlated subquery. */
    public function test_a_null_user_id_backfills_to_a_null_person_id(): void
    {
        Person::factory()->create();   // ensure a person row exists to be mismatched onto

        DB::table('handover_signoffs')->insert([
            'handover_date' => '2026-01-02',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runBackfill();

        $signoff = DB::table('handover_signoffs')->first();

        $this->assertNull($signoff->endorsed_by_person_id);
        $this->assertNull($signoff->endorsed_to_person_id);
        $this->assertNull($signoff->consultant_by_person_id);
        $this->assertNull($signoff->consultant_to_person_id);
    }

    /** All four named-role fields resolve independently, each through its own account's link. */
    public function test_all_four_fields_backfill_independently_through_their_own_link(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $c = User::factory()->create();
        $d = User::factory()->create();

        DB::table('handover_signoffs')->insert([
            'handover_date' => '2026-01-03',
            'endorsed_by_user_id' => $a->id,
            'endorsed_to_user_id' => $b->id,
            'consultant_by_user_id' => $c->id,
            'consultant_to_user_id' => $d->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runBackfill();

        $signoff = DB::table('handover_signoffs')->first();

        $this->assertSame($a->person_id, (int) $signoff->endorsed_by_person_id);
        $this->assertSame($b->person_id, (int) $signoff->endorsed_to_person_id);
        $this->assertSame($c->person_id, (int) $signoff->consultant_by_person_id);
        $this->assertSame($d->person_id, (int) $signoff->consultant_to_person_id);
    }

    /** The frozen *_name snapshot is untouched by the backfill — it is not a write target. */
    public function test_the_frozen_name_snapshot_is_not_touched_by_the_backfill(): void
    {
        $endorser = User::factory()->create(['full_name' => 'Dr Original']);

        DB::table('handover_signoffs')->insert([
            'handover_date' => '2026-01-04',
            'endorsed_by_user_id' => $endorser->id,
            'endorsed_by_name' => 'Dr Original',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $endorser->person->update(['full_name' => 'Dr Renamed']);

        $this->runBackfill();

        $signoff = DB::table('handover_signoffs')->first();

        $this->assertSame('Dr Original', $signoff->endorsed_by_name);
        $this->assertSame($endorser->person_id, (int) $signoff->endorsed_by_person_id);
    }
}
