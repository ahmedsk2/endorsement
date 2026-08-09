<?php

namespace Tests\Feature\Endorsement;

use App\Models\HandoverSignoff;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * D9 — the pickers scope DIFFERENTLY per field, and offer must equal validation for each.
 *
 *   endorsed_by / endorsed_to   claimed accounts only — these clinicians attest and receive, and
 *                               their signature is the evidence
 *   consultant_by / consultant_to  any ACTIVE PERSON, including someone with no account — the
 *                               on-call consultant is a name of record and frequently never
 *                               logs in
 *   signed_off_by               the authenticated user, by construction
 *
 * The 2026-07-26 audit restored "a picker's write-side validation must match what it OFFERS"
 * after `exists:users,id` let any account be frozen onto medico-legal evidence. D9 makes that
 * rule per-field, which is two chances to drift instead of one — so it is asserted as a MATRIX
 * rather than as examples.
 */
class PickerParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    /**
     * Every fixture: [label, person, should be offered/accepted as endorser, ditto as consultant].
     *
     * @return array<string, array{0: Person, 1: bool, 2: bool}>
     */
    private function matrix(): array
    {
        $cases = [];

        // Roster-only (no account) at each position.
        foreach ([0, 2, 3, 4, 5] as $position) {
            $person = Person::factory()->create(['position' => $position, 'full_name' => "Roster {$position}"]);
            $cases["roster-only p{$position}"] = [$person, false, $position === 3];
        }

        // Claimed, active account at each position.
        foreach ([0, 2, 3, 4, 5] as $position) {
            $user = User::factory()->create(['position' => $position, 'full_name' => "Claimed {$position}"]);
            $cases["claimed p{$position}"] = [$user->person, in_array($position, [4, 5], true), $position === 3];
        }

        // Claimed but the ACCOUNT is deactivated: cannot endorse (no live account to sign with),
        // but the person is still on the roster, so a consultant may still be named.
        $inactiveAccount = User::factory()->create(['position' => 4, 'active' => false, 'full_name' => 'Locked Out']);
        $cases['claimed p4, account inactive'] = [$inactiveAccount->person, false, false];

        $inactiveConsultant = User::factory()->create(['position' => 3, 'active' => false, 'full_name' => 'Locked Consultant']);
        $cases['claimed p3, account inactive'] = [$inactiveConsultant->person, false, true];

        // The PERSON is deactivated: nameable nowhere, whatever the account says.
        $leaver = User::factory()->create(['position' => 4, 'full_name' => 'Leaver']);
        $leaver->person->update(['active' => false]);
        $cases['person inactive'] = [$leaver->person->fresh(), false, false];

        $leaverConsultant = Person::factory()->inactive()->create(['position' => 3, 'full_name' => 'Gone Consultant']);
        $cases['roster-only p3, person inactive'] = [$leaverConsultant, false, false];

        // Soft-deleted person: gone from both, and Rule::exists never sees the SoftDeletes scope,
        // so this case is the one that catches a missing whereNull('people.deleted_at').
        $trashed = Person::factory()->create(['position' => 3, 'full_name' => 'Trashed Consultant']);
        $trashed->delete();
        $cases['roster-only p3, trashed'] = [$trashed, false, false];

        // Soft-deleted ACCOUNT with a live person: no live account, so no endorsing.
        $trashedAccount = User::factory()->create(['position' => 4, 'full_name' => 'Trashed Account']);
        $trashedAccount->delete();
        $cases['p4, account trashed'] = [$trashedAccount->person, false, false];

        // PE-03 (Task 5): `external` is a LABEL, not a permission. An external consultant and an
        // external claimed resident must offer/accept exactly as their internal twins do — this is
        // the assertion that surfacing the flag did not become a second, drifted predicate.
        $externalConsultant = Person::factory()->create([
            'position' => 3, 'external' => true, 'full_name' => 'External Consultant',
        ]);
        $cases['roster-only p3, external'] = [$externalConsultant, false, true];

        $externalResident = User::factory()->create([
            'position' => 4, 'external' => true, 'full_name' => 'External Resident',
        ]);
        $cases['claimed p4, external'] = [$externalResident->person, true, false];

        return $cases;
    }

    public function test_every_field_accepts_exactly_who_it_offers(): void
    {
        $unit = Unit::where('code', 'PICU')->firstOrFail();
        $actor = User::factory()->create(['position' => 2]);
        $cases = $this->matrix();

        $page = $this->actingAs($actor)->get("/endorsement/{$unit->code}/2026-08-08")->assertOk();

        $offered = ['endorsers' => [], 'consultants' => []];
        $page->assertInertia(function (Assert $p) use (&$offered) {
            $staff = $p->toArray()['props']['staff'];
            $offered['endorsers'] = collect($staff['endorsers'])->reject(fn ($s) => $s['retired'] ?? false)->pluck('id')->all();
            $offered['consultants'] = collect($staff['consultants'])->reject(fn ($s) => $s['retired'] ?? false)->pluck('id')->all();
        });

        foreach ($cases as $label => [$person, $endorsable, $consultable]) {
            $this->assertSame($endorsable, in_array($person->id, $offered['endorsers'], true), "offer/endorser: {$label}");
            $this->assertSame($consultable, in_array($person->id, $offered['consultants'], true), "offer/consultant: {$label}");

            foreach ([
                'endorsed_by_person_id' => $endorsable,
                'endorsed_to_person_id' => $endorsable,
                'consultant_by_person_id' => $consultable,
                'consultant_to_person_id' => $consultable,
            ] as $field => $accepted) {
                $response = $this->actingAs($actor)
                    ->patch("/endorsement/{$unit->code}/2026-08-08/signoff", [$field => $person->id]);

                if ($accepted) {
                    $response->assertSessionHasNoErrors();
                    // Accepted means STORED, not merely un-refused: `sometimes|nullable` would
                    // silently pass a field that never reached the row.
                    $this->assertSame(
                        $person->id,
                        (int) HandoverSignoff::where('unit_id', $unit->id)
                            ->whereDate('handover_date', '2026-08-08')
                            ->value($field),
                        "stored/{$field}: {$label}"
                    );
                } else {
                    $response->assertSessionHasErrors($field);
                }
            }
        }
    }
}
