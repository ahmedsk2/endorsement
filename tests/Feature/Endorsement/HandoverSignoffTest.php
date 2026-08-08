<?php

namespace Tests\Feature\Endorsement;

use App\Models\AuditLog;
use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * H / GAP-5 — the shift SIGN-OFF (attestation) on the handover sheet.
 *
 * Legacy spec: `validate-endorsement.php:8-16` writes ONE day-header row into the legacy
 * `endorsement` table (`Dates`, `endorsedby`, `endorsedto`, `consultantby`, `consultantto`,
 * `time`) — i.e. the attestation is PER DAY, not per patient row. The pickers are at
 * `picu-endorsement-patients.php:362` (consultants), `:397` (endorsed by/to) and `:431` (time);
 * the printed sheet renders it at `picu-endorsement-print.php:199` (header "Consultant Covering"
 * + "TIME") and `:305`/`:324` (footer "Endorsed By" / "Endorsed To").
 *
 * Who endorsed to whom, and at what time, is a MEDICO-LEGAL record — so:
 *  - the stored NAME is snapshotted alongside the user FK, so a later rename/soft-delete cannot
 *    rewrite what a signed sheet says;
 *  - sign-off is reversible ONLY through an audited, reason-carrying correction path.
 */
class HandoverSignoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    private function editor(): User
    {
        return User::factory()->create(['position' => 2]);
    }

    private function nurse(): User
    {
        return User::factory()->create(['position' => 1]);
    }

    /** Position 0 — the only role that may REOPEN a signed handover (a medico-legal reversal). */
    private function admin(): User
    {
        return User::factory()->create(['position' => 0]);
    }

    private function picu(): Unit
    {
        return Unit::where('code', 'PICU')->firstOrFail();
    }

    private function handover(string $date): Handover
    {
        return Handover::create([
            'unit_id' => $this->picu()->id,
            'handover_date' => $date,
            'bed' => '1',
            'mrn' => 'M-'.fake()->unique()->numerify('#####'),
            'patient_name' => 'Test Child',
        ]);
    }

    // ---------------------------------------------------------------- capture

    public function test_sheet_exposes_the_signoff_and_the_staff_pickers(): void
    {
        $this->handover('2026-07-10');
        $resident = User::factory()->create(['position' => 4, 'full_name' => 'Dr Resident']);
        $consultant = User::factory()->create(['position' => 3, 'full_name' => 'Dr Consultant']);

        $this->actingAs($this->editor())
            ->get('/endorsement/PICU/2026-07-10')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('signoff')
                ->where('signoff.signed_off', false)
                // Legacy sourced endorsed by/to from `members WHERE position='4'` (residents only);
                // that list is now WIDENED (see the picker test below). Consultants stay position 3.
                ->has('staff.endorsers')
                ->has('staff.consultants', fn (Assert $list) => $list->where('0.id', $consultant->id)->etc())
                ->has('timeOptions')
            );

        $this->assertNotNull($resident->id);
    }

    /**
     * Ruling 6 — the endorsed-by / endorsed-to pickers are ACTIVE RESIDENTS ALONE (legacy
     * parity: `members WHERE position='4'`). Everyone else is deliberately excluded, and an
     * inactive resident is no longer offered.
     *
     * D9 — since P0c the list is PEOPLE, and an endorser specifically needs a live claimed
     * account (their signature is the evidence); a roster-only Resident (no account at all)
     * must not appear, which PickerParityTest proves as a matrix and this proves in context.
     */
    public function test_the_endorser_pickers_list_active_residents_with_an_account(): void
    {
        $this->handover('2026-07-10');
        $charge = User::factory()->create(['position' => 2, 'full_name' => 'Aa Charge']);
        $consultant = User::factory()->create(['position' => 3, 'full_name' => 'Bb Consultant']);
        $resident = User::factory()->create(['position' => 4, 'full_name' => 'Cc Resident']);
        $nurse = User::factory()->create(['position' => 1, 'full_name' => 'Dd Nurse']);
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'Ee Admin']);
        User::factory()->create(['position' => 4, 'full_name' => 'Ff Inactive', 'active' => false]);
        $rosterOnlyResident = \App\Models\Person::factory()->create(['position' => 4, 'full_name' => 'Gg Roster Only']);

        $this->actingAs($this->editor())
            ->get('/endorsement/PICU/2026-07-10')
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($charge, $consultant, $resident, $nurse, $admin, $rosterOnlyResident) {
                $endorsers = collect($page->toArray()['props']['staff']['endorsers']);
                $ids = $endorsers->pluck('id')->all();

                $this->assertContains($resident->person_id, $ids);
                $this->assertNotContains($charge->person_id, $ids);
                $this->assertNotContains($consultant->person_id, $ids);
                $this->assertNotContains($nurse->person_id, $ids);
                $this->assertNotContains($admin->person_id, $ids);
                $this->assertNotContains($rosterOnlyResident->id, $ids);
                $this->assertNotContains('Ff Inactive', $endorsers->pluck('name')->all());
                $this->assertNotContains('Gg Roster Only', $endorsers->pluck('name')->all());

                // The CONSULTANT fields answer a different question — who is COVERING — so they
                // are position 3 only.
                $consultants = collect($page->toArray()['props']['staff']['consultants'])->pluck('id')->all();
                $this->assertSame([$consultant->person_id], $consultants);
            });
    }

    /** The endorsing resident's name is frozen at write time — a rename never rewrites a signed sheet. */
    public function test_the_endorser_name_snapshot_survives_a_later_rename(): void
    {
        $this->handover('2026-07-10');
        $resident = User::factory()->create(['position' => 4, 'full_name' => 'Dr Original']);

        $this->actingAs($this->editor())->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $resident->person_id,
            'sign_off' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $s = HandoverSignoff::firstOrFail();
        $this->assertSame($resident->person_id, $s->endorsed_by_person_id);
        $this->assertSame('Dr Original', $s->endorsed_by_name);
        // The wire contract renamed to *_person_id (P0c/D9, finding 3): the legacy *_user_id
        // columns are FROZEN and a new write must leave them alone.
        $this->assertNull($s->endorsed_by_user_id);

        $resident->update(['full_name' => 'Renamed Later']);
        $this->assertSame('Dr Original', $s->fresh()->endorsed_by_name);
    }

    // ------------------------------------------------------------ clock time

    /**
     * A handover that genuinely happened at 02:40 must be recordable as 02:40. The legacy two-label
     * picker had no way to say that. Storage: `endorsement_time` keeps the legacy DISPLAY STRING
     * (parity — the printed sheet reads as it always did) and `endorsement_time_minutes` carries the
     * unambiguous machine value.
     */
    public function test_a_real_clock_time_can_be_recorded_alongside_the_legacy_labels(): void
    {
        $this->handover('2026-07-10');
        $by = User::factory()->create(['position' => 4]);

        $this->actingAs($this->editor())->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $by->person_id,
            'endorsement_time' => '02:40',
            'sign_off' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $s = HandoverSignoff::firstOrFail();
        $this->assertSame('02:40', $s->endorsement_time);
        $this->assertSame(160, $s->endorsement_time_minutes);
    }

    public function test_a_legacy_shift_label_is_stored_verbatim_and_still_resolves_to_minutes(): void
    {
        $this->handover('2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/PICU/2026-07-10/signoff', ['endorsement_time' => '7:30 Am'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $s = HandoverSignoff::firstOrFail();
        // Character-for-character legacy parity on the display string.
        $this->assertSame('7:30 Am', $s->endorsement_time);
        $this->assertSame(450, $s->endorsement_time_minutes);
    }

    public function test_a_12_hour_entry_is_normalized_to_an_unambiguous_24_hour_label(): void
    {
        $this->handover('2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/PICU/2026-07-10/signoff', ['endorsement_time' => '2:40 pm'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $s = HandoverSignoff::firstOrFail();
        $this->assertSame('14:40', $s->endorsement_time);
        $this->assertSame(880, $s->endorsement_time_minutes);
    }

    public function test_a_time_that_names_no_clock_time_is_rejected(): void
    {
        $this->handover('2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/PICU/2026-07-10/signoff', ['endorsement_time' => 'sometime after rounds'])
            ->assertSessionHasErrors('endorsement_time');

        $this->assertSame(0, HandoverSignoff::count());
    }

    public function test_clearing_the_time_is_allowed(): void
    {
        $this->handover('2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/PICU/2026-07-10/signoff', ['endorsement_time' => '13:30'])
            ->assertRedirect();

        $this->actingAs($this->editor())
            ->patch('/endorsement/PICU/2026-07-10/signoff', ['endorsement_time' => null])
            ->assertRedirect()->assertSessionHasNoErrors();

        $s = HandoverSignoff::firstOrFail();
        $this->assertNull($s->endorsement_time);
        $this->assertNull($s->endorsement_time_minutes);
    }

    public function test_an_editor_can_sign_off_a_handover_day(): void
    {
        $this->handover('2026-07-10');
        $by = User::factory()->create(['position' => 4, 'full_name' => 'Dr Alpha']);
        $to = User::factory()->create(['position' => 4, 'full_name' => 'Dr Beta']);
        $cby = User::factory()->create(['position' => 3, 'full_name' => 'Dr Gamma']);
        $cto = User::factory()->create(['position' => 3, 'full_name' => 'Dr Delta']);

        $actor = $this->editor();

        $this->actingAs($actor)
            ->patch('/endorsement/PICU/2026-07-10/signoff', [
                'endorsed_by_person_id' => $by->person_id,
                'endorsed_to_person_id' => $to->person_id,
                'consultant_by_person_id' => $cby->person_id,
                'consultant_to_person_id' => $cto->person_id,
                'endorsement_time' => '7:30 Am',
                'sign_off' => true,
            ])
            ->assertRedirect();

        $s = HandoverSignoff::where('unit_id', $this->picu()->id)->firstOrFail();

        $this->assertSame($by->person_id, $s->endorsed_by_person_id);
        $this->assertSame('Dr Alpha', $s->endorsed_by_name);
        $this->assertSame('Dr Beta', $s->endorsed_to_name);
        $this->assertSame('Dr Gamma', $s->consultant_by_name);
        $this->assertSame('Dr Delta', $s->consultant_to_name);
        $this->assertSame('7:30 Am', $s->endorsement_time);
        $this->assertNotNull($s->signed_off_at);
        $this->assertSame($actor->id, $s->signed_off_by_user_id);

        $this->assertDatabaseHas('audit_log', ['action' => 'endorsement_signoff']);
    }

    public function test_the_stored_name_survives_a_later_rename_of_the_signing_user(): void
    {
        $this->handover('2026-07-10');
        $by = User::factory()->create(['position' => 4, 'full_name' => 'Dr Alpha']);

        $this->actingAs($this->editor())
            ->patch('/endorsement/PICU/2026-07-10/signoff', [
                'endorsed_by_person_id' => $by->person_id,
                'sign_off' => true,
            ])->assertRedirect();

        $by->update(['full_name' => 'Dr Renamed']);

        $this->assertSame('Dr Alpha', HandoverSignoff::firstOrFail()->endorsed_by_name);
    }

    public function test_signing_off_requires_at_least_one_endorser(): void
    {
        $this->handover('2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/PICU/2026-07-10/signoff', ['sign_off' => true])
            ->assertSessionHasErrors('endorsed_by_person_id');

        $this->assertDatabaseCount('handover_signoffs', 0);
    }

    public function test_details_can_be_saved_without_signing_off(): void
    {
        $this->handover('2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/PICU/2026-07-10/signoff', ['endorsement_time' => '13:30'])
            ->assertRedirect();

        $s = HandoverSignoff::firstOrFail();
        $this->assertSame('13:30', $s->endorsement_time);
        $this->assertNull($s->signed_off_at);
    }

    // ---------------------------------------------------------------- correction path

    public function test_a_signed_day_is_locked_until_it_is_reopened(): void
    {
        $this->handover('2026-07-10');
        $by = User::factory()->create(['position' => 4, 'full_name' => 'Dr Alpha']);
        $editor = $this->editor();

        $this->actingAs($editor)->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $by->person_id,
            'sign_off' => true,
        ])->assertRedirect();

        // A second write against a signed day is refused rather than silently overwriting the
        // attestation.
        $this->actingAs($editor)
            ->patch('/endorsement/PICU/2026-07-10/signoff', ['endorsement_time' => '13:30'])
            ->assertSessionHasErrors('sign_off');

        $this->assertNull(HandoverSignoff::firstOrFail()->endorsement_time);
    }

    public function test_reopening_is_audited_reason_bearing_and_non_destructive(): void
    {
        $this->handover('2026-07-10');
        $by = User::factory()->create(['position' => 4, 'full_name' => 'Dr Alpha']);
        $editor = $this->editor();

        $this->actingAs($editor)->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $by->person_id,
            'sign_off' => true,
        ])->assertRedirect();

        // Reopening is ADMINISTRATOR-ONLY (see the gate tests below) — a reason is still mandatory.
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/endorsement/PICU/2026-07-10/signoff/reopen', [])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)
            ->post('/endorsement/PICU/2026-07-10/signoff/reopen', ['reason' => 'Wrong receiving resident selected'])
            ->assertRedirect();

        $s = HandoverSignoff::firstOrFail();
        $this->assertNull($s->signed_off_at);
        // NON-DESTRUCTIVE: who signed, and what it said, is retained.
        $this->assertSame('Dr Alpha', $s->endorsed_by_name);
        $this->assertNotNull($s->reopened_at);
        $this->assertSame('Wrong receiving resident selected', $s->reopen_reason);

        $audit = AuditLog::where('action', 'endorsement_signoff_reopen')->firstOrFail();
        $this->assertStringContainsString('date=2026-07-10', (string) $audit->detail);
    }

    // ---------------------------------------------------------------- surfacing

    public function test_the_day_index_distinguishes_a_signed_day_from_an_unsigned_one(): void
    {
        $this->handover('2026-07-10');
        $this->handover('2026-07-11');
        $by = User::factory()->create(['position' => 4, 'full_name' => 'Dr Alpha']);

        $this->actingAs($this->editor())->patch('/endorsement/PICU/2026-07-11/signoff', [
            'endorsed_by_person_id' => $by->person_id,
            'sign_off' => true,
        ])->assertRedirect();

        $this->actingAs($this->editor())
            ->get('/endorsement/PICU')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // newest first
                ->where('dates.0.date', '2026-07-11')
                ->where('dates.0.signed_off', true)
                ->where('dates.1.date', '2026-07-10')
                ->where('dates.1.signed_off', false)
                ->etc()
            );
    }

    public function test_the_print_sheet_carries_the_signoff(): void
    {
        $this->handover('2026-07-10');
        $by = User::factory()->create(['position' => 4, 'full_name' => 'Dr Alpha']);
        $cby = User::factory()->create(['position' => 3, 'full_name' => 'Dr Gamma']);

        $this->actingAs($this->editor())->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $by->person_id,
            'consultant_by_person_id' => $cby->person_id,
            'endorsement_time' => '7:30 Am',
            'sign_off' => true,
        ])->assertRedirect();

        $this->actingAs($this->editor())
            ->get('/endorsement/PICU/2026-07-10/print')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('signoff.endorsed_by_name', 'Dr Alpha')
                ->where('signoff.consultant_by_name', 'Dr Gamma')
                ->where('signoff.endorsement_time', '7:30 Am')
                ->where('signoff.signed_off', true)
                ->etc()
            );
    }

    // ------------------------------------------------- reopen is admin-only

    /**
     * Reopening another shift's signed attestation is a MEDICO-LEGAL act — it un-signs a record a
     * named clinician put their name to. `endorsement.edit` (Charge Nurse / Consultant / Resident)
     * is the right gate for writing a sheet and the wrong one for reversing someone else's
     * signature, so reopening is narrowed to Administrator.
     *
     * The operational cost is real and accepted: outside admin hours a ward that signed with the
     * wrong receiving clinician cannot correct it itself. The refusal therefore names WHO to
     * contact rather than returning a bare 403 — see the message test below.
     */
    public function test_an_editor_can_no_longer_reopen_a_signed_handover(): void
    {
        $this->handover('2026-07-10');
        $by = User::factory()->create(['position' => 4]);
        $editor = $this->editor();

        $this->actingAs($editor)->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $by->person_id,
            'sign_off' => true,
        ])->assertRedirect();

        $signedAt = HandoverSignoff::firstOrFail()->signed_off_at;

        $this->actingAs($editor)
            ->post('/endorsement/PICU/2026-07-10/signoff/reopen', ['reason' => 'Wrong receiving resident'])
            ->assertForbidden();

        // The attestation is untouched: still signed, nothing stamped.
        $s = HandoverSignoff::firstOrFail();
        $this->assertEquals($signedAt, $s->signed_off_at);
        $this->assertNull($s->reopened_at);
        $this->assertNull($s->reopen_reason);
    }

    public function test_the_refusal_names_who_to_contact_and_is_audited(): void
    {
        $this->handover('2026-07-10');
        $by = User::factory()->create(['position' => 4]);
        User::factory()->create(['position' => 0, 'full_name' => 'Dr Site Administrator']);
        $editor = $this->editor();

        $this->actingAs($editor)->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $by->person_id,
            'sign_off' => true,
        ])->assertRedirect();

        $response = $this->actingAs($editor)
            ->post('/endorsement/PICU/2026-07-10/signoff/reopen', ['reason' => 'Wrong receiving resident']);

        $response->assertForbidden();
        // Not a bare 403: the ward is told the rule AND who can act on it.
        $message = $response->exception?->getMessage() ?? '';
        $this->assertStringContainsString('Administrator', $message);
        $this->assertStringContainsString('Dr Site Administrator', $message);

        // PHI-free audit row for the refused reversal (ids/dates only).
        $audit = AuditLog::where('action', 'endorsement_signoff_reopen_denied')->firstOrFail();
        $this->assertStringContainsString('date=2026-07-10', (string) $audit->detail);
        $this->assertStringNotContainsString('Wrong receiving resident', (string) $audit->detail);
    }

    /** The sheet tells a non-admin who to contact BEFORE they try, so nobody is left guessing. */
    public function test_the_sheet_exposes_the_reopen_permission_and_the_contacts(): void
    {
        $this->handover('2026-07-10');
        $by = User::factory()->create(['position' => 4]);
        User::factory()->create(['position' => 0, 'full_name' => 'Dr Site Administrator']);
        $editor = $this->editor();

        $this->actingAs($editor)->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $by->person_id,
            'sign_off' => true,
        ])->assertRedirect();

        $this->actingAs($editor)
            ->get('/endorsement/PICU/2026-07-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('signoff.can_reopen', false)
                ->where('signoff.reopen_contacts', ['Dr Site Administrator'])
                ->etc()
            );

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU/2026-07-10')
            ->assertInertia(fn (Assert $page) => $page->where('signoff.can_reopen', true)->etc());
    }

    // ---------------------------------------------------------------- gates

    public function test_a_nurse_cannot_sign_off(): void
    {
        $this->handover('2026-07-10');

        $this->actingAs($this->nurse())
            ->patch('/endorsement/PICU/2026-07-10/signoff', ['endorsement_time' => '13:30'])
            ->assertForbidden();

        $this->actingAs($this->nurse())
            ->post('/endorsement/PICU/2026-07-10/signoff/reopen', ['reason' => 'x'])
            ->assertForbidden();
    }

    public function test_a_guest_cannot_sign_off(): void
    {
        $this->handover('2026-07-10');

        $this->patch('/endorsement/PICU/2026-07-10/signoff', ['endorsement_time' => '13:30'])
            ->assertRedirect('/login');
    }

    public function test_every_unit_signs_off_independently(): void
    {
        $resident = User::factory()->create(['position' => 4, 'full_name' => 'Dr R']);

        foreach (['NICU', 'SCBU', 'WARD'] as $code) {
            $this->actingAs($this->editor())
                ->patch('/endorsement/'.$code.'/2026-07-10/signoff', [
                    'endorsed_by_person_id' => $resident->person_id,
                    'sign_off' => true,
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(3, HandoverSignoff::whereNotNull('signed_off_at')->count());
    }

    /**
     * Ruling 5 — WARD has a SINGLE consultant field ("Consultant Oncall") stored in
     * consultant_by_*; a submitted receiving consultant is dropped, not persisted.
     */
    public function test_ward_maps_the_oncall_consultant_and_drops_the_receiving_field(): void
    {
        $oncall = User::factory()->create(['position' => 3, 'full_name' => 'Dr Oncall']);
        $other = User::factory()->create(['position' => 3, 'full_name' => 'Dr Other']);

        $this->actingAs($this->editor())
            ->patch('/endorsement/WARD/2026-07-10/signoff', [
                'consultant_by_person_id' => $oncall->person_id,
                'consultant_to_person_id' => $other->person_id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $s = HandoverSignoff::firstOrFail();
        $this->assertSame($oncall->person_id, $s->consultant_by_person_id);
        $this->assertSame('Dr Oncall', $s->consultant_by_name);
        $this->assertNull($s->consultant_to_person_id, 'WARD has no receiving consultant (ruling 5)');
        $this->assertNull($s->consultant_to_name);
    }

    /** Spec §6 — a signed day is LOCKED: row writes are refused until an audited reopen. */
    public function test_a_signed_day_locks_row_writes_too(): void
    {
        $row = $this->handover('2026-07-10');
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($this->editor())->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $resident->person_id,
            'sign_off' => true,
        ])->assertRedirect();

        $editor = $this->editor();
        $this->actingAs($editor)
            ->patch('/endorsement/rows/'.$row->id, ['plan' => 'late edit'])
            ->assertSessionHasErrors();
        $this->assertStringNotContainsString('late edit', (string) $row->fresh()->plan);

        $this->actingAs($editor)
            ->post('/endorsement/PICU/2026-07-10/rows', ['mrn' => 'LOCK-1'])
            ->assertSessionHasErrors();

        $this->actingAs($editor)->delete('/endorsement/rows/'.$row->id)->assertSessionHasErrors();
        $this->assertNull($row->fresh()->deleted_at);
    }
}
