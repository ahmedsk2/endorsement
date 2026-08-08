<?php

namespace Tests\Feature\Endorsement;

use App\Models\Handover;
use App\Models\HandoverRevision;
use App\Models\Unit;
use App\Models\UnitFieldDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SPC-TM-002 — the highest-residual finding in the threat model.
 *
 * This system exists to be a medico-legal handover record, and its core integrity property
 * is "what was written and attested at handover time". Nothing preserved it: updateRow
 * overwrote in place and audited `row=<id>`, proving THAT an edit happened while retaining
 * nothing about what was there before. A reopen, a rewrite and a re-sign after an adverse
 * event left an orderly-looking trail and an unreconstructable record.
 */
class RevisionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function setup4(): array
    {
        $this->seed(\Database\Seeders\ReferenceSeeder::class);
        $this->seed(\Database\Seeders\AccessControlSeeder::class);

        $unit = Unit::where('code', 'PICU')->firstOrFail();
        $user = User::factory()->create(['position' => 4, 'active' => true]);

        $row = Handover::create([
            'unit_id' => $unit->id,
            'handover_date' => '2026-07-26',
            'bed' => '2',
            'patient_name' => 'Test Patient',
            'mrn' => 'MRN-1',
            'disease' => '<p>Septic shock</p>',
            'details' => '<p>On noradrenaline</p>',
            'plan' => '<p>Wean vasopressors</p>',
            'nevent' => '',
            'author_user_id' => $user->id,
        ]);

        return [$unit, $user, $row];
    }

    public function test_the_previous_clinical_text_is_kept_when_a_field_changes(): void
    {
        [$unit, $user, $row] = $this->setup4();

        $this->actingAs($user)->patch("/endorsement/rows/{$row->id}", [
            'bed' => '2',
            'patient_name' => 'Test Patient',
            'mrn' => 'MRN-1',
            'disease' => '<p>Septic shock</p>',
            'details' => '<p>On noradrenaline</p>',
            'plan' => '<p>Extubate today</p>',      // changed
            'nevent' => '',
        ])->assertSessionHasNoErrors();

        $revision = HandoverRevision::where('handover_id', $row->id)->where('field', 'plan')->firstOrFail();

        $this->assertStringContainsString('Wean vasopressors', (string) $revision->old_value);
        $this->assertSame($user->id, $revision->changed_by_user_id);
    }

    public function test_the_superseded_value_is_encrypted_at_rest_like_the_live_column(): void
    {
        [$unit, $user, $row] = $this->setup4();

        $this->actingAs($user)->patch("/endorsement/rows/{$row->id}", [
            'bed' => '2', 'patient_name' => 'Test Patient', 'mrn' => 'MRN-1',
            'disease' => '<p>Septic shock</p>', 'details' => '<p>On noradrenaline</p>',
            'plan' => '<p>Extubate today</p>', 'nevent' => '',
        ]);

        // A superseded problem list is still a child's diagnosis. Read the raw column.
        $raw = DB::table('handover_revisions')->where('field', 'plan')->value('old_value');

        $this->assertStringNotContainsString('Wean vasopressors', (string) $raw);
    }

    public function test_an_unchanged_field_does_not_create_a_revision(): void
    {
        [$unit, $user, $row] = $this->setup4();

        // Save-on-blur re-submits every field on every keystroke pause; recording all of
        // them would bury the real changes in noise.
        $this->actingAs($user)->patch("/endorsement/rows/{$row->id}", [
            'bed' => '2', 'patient_name' => 'Test Patient', 'mrn' => 'MRN-1',
            'disease' => '<p>Septic shock</p>', 'details' => '<p>On noradrenaline</p>',
            'plan' => '<p>Wean vasopressors</p>', 'nevent' => '',
        ]);

        $this->assertSame(0, HandoverRevision::count());
    }

    public function test_history_survives_the_edit_it_exists_to_document(): void
    {
        [$unit, $user, $row] = $this->setup4();

        // Two successive rewrites of the same field: both prior values must remain.
        foreach (['<p>Second</p>', '<p>Third</p>'] as $plan) {
            $this->actingAs($user)->patch("/endorsement/rows/{$row->id}", [
                'bed' => '2', 'patient_name' => 'Test Patient', 'mrn' => 'MRN-1',
                'disease' => '<p>Septic shock</p>', 'details' => '<p>On noradrenaline</p>',
                'plan' => $plan, 'nevent' => '',
            ]);
        }

        $history = HandoverRevision::where('field', 'plan')->orderBy('id')->get();

        $this->assertCount(2, $history);
        $this->assertStringContainsString('Wean vasopressors', (string) $history[0]->old_value);
        $this->assertStringContainsString('Second', (string) $history[1]->old_value);
    }

    /**
     * P0b (finding 3) — `extra_fields` cannot be added to TRACKED as-is: recordRevisions()
     * casts the before-image to `(string)`, and an array raises "Array to string conversion"
     * (promoted to a thrown ErrorException by HandleExceptions). Custom fields get their own
     * before-image path instead, one row per changed subkey, named `extra_fields.{key}` —
     * still giving a reopen-rewrite-resign case something to reconstruct from.
     */
    public function test_editing_a_custom_field_records_a_before_image_revision(): void
    {
        [$unit, $user, $row] = $this->setup4();

        UnitFieldDefinition::create(['unit_id' => $unit->id, 'key' => 'weight', 'label' => 'Weight']);
        $row->update(['extra_fields' => ['weight' => '3.0']]);

        $this->actingAs($user)
            ->patch("/endorsement/rows/{$row->id}", ['extra_fields' => ['weight' => '3.5']])
            ->assertSessionHasNoErrors();

        $revision = HandoverRevision::where('handover_id', $row->id)
            ->where('field', 'extra_fields.weight')
            ->firstOrFail();

        $this->assertSame('3.0', $revision->old_value);
        $this->assertSame($user->id, $revision->changed_by_user_id);
    }

    /** Save-on-blur re-submits the same value on every keystroke pause; a no-op resave must not
     *  bury real changes in noise, exactly like the tracked-column case above. */
    public function test_an_unchanged_custom_field_does_not_create_a_revision(): void
    {
        [$unit, $user, $row] = $this->setup4();

        UnitFieldDefinition::create(['unit_id' => $unit->id, 'key' => 'weight', 'label' => 'Weight']);
        $row->update(['extra_fields' => ['weight' => '3.0']]);

        $this->actingAs($user)
            ->patch("/endorsement/rows/{$row->id}", ['extra_fields' => ['weight' => '3.0']])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, HandoverRevision::where('field', 'extra_fields.weight')->count());
    }
}
