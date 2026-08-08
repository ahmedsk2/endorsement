<?php

namespace Tests\Unit\Casts;

use App\Casts\ExtraRowFields;
use App\Models\Unit;
use PHPUnit\Framework\TestCase;

/**
 * `extra_row_fields` drives EndorsementController::validateRow()'s rule set, whose output
 * reaches `$handover->update()` unfiltered — see the cast's own docblock. So the allow-list
 * is the thing actually protecting a clinical row from being moved between units or having
 * its recorded author rewritten. These tests pin that it is enforced on BOTH directions, and
 * that a malformed value degrades to "no extra columns" rather than throwing.
 */
class ExtraRowFieldsTest extends TestCase
{
    public function test_a_permitted_list_round_trips(): void
    {
        $cast = new ExtraRowFields();
        $model = new Unit();

        $stored = $cast->set($model, 'extra_row_fields', ['dob', 'ward_unit'], []);

        $this->assertSame(['dob', 'ward_unit'], $cast->get($model, 'extra_row_fields', $stored, []));
    }

    public function test_a_disallowed_key_is_stripped_on_set(): void
    {
        $cast = new ExtraRowFields();
        $model = new Unit();

        $stored = $cast->set($model, 'extra_row_fields', ['dob', 'unit_id'], []);

        $this->assertSame(['dob'], json_decode($stored, true));
    }

    public function test_a_disallowed_key_already_in_the_database_is_stripped_on_get(): void
    {
        $cast = new ExtraRowFields();
        $model = new Unit();

        // Simulates a bad row already in the database — e.g. a hand-edited units table —
        // rather than one written through the cast.
        $result = $cast->get($model, 'extra_row_fields', '["unit_id","dob"]', []);

        $this->assertSame(['dob'], $result);
    }

    public function test_a_non_array_json_value_yields_an_empty_list(): void
    {
        $cast = new ExtraRowFields();
        $model = new Unit();

        $this->assertSame([], $cast->get($model, 'extra_row_fields', '"dob"', []));
    }

    public function test_null_yields_an_empty_list(): void
    {
        $cast = new ExtraRowFields();
        $model = new Unit();

        $this->assertSame([], $cast->get($model, 'extra_row_fields', null, []));
        $this->assertSame([], json_decode($cast->set($model, 'extra_row_fields', null, []), true));
    }
}
