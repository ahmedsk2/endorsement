<?php

namespace Tests\Feature\Security;

use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\PendingRegistration;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressions for defects found by the 2026-07-26 security audit and confirmed in source.
 *
 * Each of these passed the whole existing suite, because each is a gap between what the UI
 * OFFERS and what the server ACCEPTS — the class of bug that only appears when you ask
 * "what happens if the client sends something the form never would?"
 */
class AuditHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function seedAccess(): void
    {
        $this->seed(\Database\Seeders\ReferenceSeeder::class);
        $this->seed(\Database\Seeders\AccessControlSeeder::class);
    }

    private function unit(): Unit
    {
        return Unit::where('code', 'PICU')->firstOrFail();
    }

    /**
     * SPC-API-001 / SPC-TM-001 (high). The endorser pickers offer only ACTIVE Residents and
     * Chief Residents, but the write path validated `exists:users,id` — any id in the table.
     * The handler then freezes that person's name AND their handwritten signature onto a
     * medico-legal record, so this was a forgery path, not a tidiness issue.
     *
     * D9 (P0c) narrows this further: an endorser needs a claimed, LIVE ACCOUNT, not merely the
     * right role — a roster-only Resident (no account at all) is refused for exactly the same
     * reason a deactivated one is: their signature cannot be the evidence if there is nothing
     * to sign with.
     */
    public function test_signoff_refuses_an_endorser_who_is_not_an_active_resident_or_chief(): void
    {
        $this->seedAccess();
        $unit = $this->unit();

        $actor = User::factory()->create(['position' => 4, 'active' => true]);
        $consultant = User::factory()->create(['position' => 3, 'active' => true]);
        $deactivated = User::factory()->create(['position' => 4, 'active' => false]);
        $rosterOnly = Person::factory()->create(['position' => 4, 'full_name' => 'Roster Only Resident']);

        foreach ([$consultant->person_id, $deactivated->person_id, $rosterOnly->id] as $targetPersonId) {
            $this->actingAs($actor)
                ->patch(
                    "/endorsement/{$unit->code}/2026-07-26/signoff",
                    ['endorsed_by_person_id' => $targetPersonId],
                )
                ->assertSessionHasErrors('endorsed_by_person_id');
        }

        $this->assertDatabaseCount('handover_signoffs', 0);
    }

    public function test_signoff_still_accepts_a_legitimate_active_resident(): void
    {
        $this->seedAccess();
        $unit = $this->unit();

        $actor = User::factory()->create(['position' => 4, 'active' => true]);
        $chief = User::factory()->create(['position' => 5, 'active' => true]);

        $this->actingAs($actor)
            ->patch("/endorsement/{$unit->code}/2026-07-26/signoff", ['endorsed_by_person_id' => $chief->person_id])
            ->assertSessionHasNoErrors();

        $this->assertSame($chief->person_id, HandoverSignoff::first()->endorsed_by_person_id);
    }

    /**
     * D9's other half: the CONSULTANT fields need only an active PERSON — a roster-only
     * consultant, with no account at all, is a valid and expected attestation (the on-call
     * consultant frequently never logs in). This is the mirror image of the test above, and
     * both must hold simultaneously or the picker has drifted off the D9 split.
     */
    public function test_signoff_accepts_a_roster_only_consultant_who_has_no_account(): void
    {
        $this->seedAccess();
        $unit = $this->unit();

        $actor = User::factory()->create(['position' => 4, 'active' => true]);
        $rosterOnlyConsultant = Person::factory()->create(['position' => 3, 'full_name' => 'Roster Only Consultant']);

        $this->actingAs($actor)
            ->patch("/endorsement/{$unit->code}/2026-07-26/signoff", ['consultant_by_person_id' => $rosterOnlyConsultant->id])
            ->assertSessionHasNoErrors();

        $s = HandoverSignoff::firstOrFail();
        $this->assertSame($rosterOnlyConsultant->id, $s->consultant_by_person_id);
        $this->assertSame('Roster Only Consultant', $s->consultant_by_name);
    }

    /**
     * SPC-API-006 (confirmed). assertDayUnlocked guards storeRow/updateRow/deleteRow but NOT
     * newDay, whose only check was for existing ROWS. Sign off an empty day, then start it,
     * and clinical rows land inside a day that is supposed to be locked evidence.
     */
    public function test_new_day_refuses_to_populate_a_signed_off_day(): void
    {
        $this->seedAccess();
        $unit = $this->unit();
        $actor = User::factory()->create(['position' => 4, 'active' => true]);

        HandoverSignoff::create([
            'unit_id' => $unit->id,
            'handover_date' => '2026-07-26',
            'signed_off_at' => now(),
            'signed_off_by_user_id' => $actor->id,
        ]);

        // A locked day is reported as a validation error on `sheet`, the same way
        // storeRow/updateRow/deleteRow already report it — not as a 403.
        $this->actingAs($actor)
            ->post("/endorsement/{$unit->code}/new-day", ['date' => '2026-07-26'])
            ->assertSessionHasErrors('sheet');

        $this->assertSame(0, Handover::where('unit_id', $unit->id)->count());
    }

    /**
     * SPC-API-009 / SPC-TM-014 (confirmed). newDay took a free string and handed it to
     * strtotime, so "+5 years" or "last monday" created real handover rows — and a
     * backdated day makes the missed-days compliance page report a handover that never
     * happened, corrupting the one metric this system exists to improve.
     */
    public function test_new_day_rejects_a_non_iso_date(): void
    {
        $this->seedAccess();
        $unit = $this->unit();
        $actor = User::factory()->create(['position' => 4, 'active' => true]);

        foreach (['+5 years', 'last monday', 'tomorrow'] as $expression) {
            $this->actingAs($actor)
                ->post("/endorsement/{$unit->code}/new-day", ['date' => $expression])
                ->assertSessionHasErrors('date');
        }

        $this->assertSame(0, Handover::where('unit_id', $unit->id)->count());
    }

    /**
     * SPC-TM-008 (confirmed). docs/COMPLIANCE.md claimed "Email address confirmed before an
     * account is activated". approve() never checked it. With /register open to the
     * internet, an unverified outsider could be approved into full four-unit PHI access.
     */
    public function test_approval_is_refused_when_the_email_was_never_verified(): void
    {
        $this->seedAccess();
        $admin = User::factory()->create(['position' => 0, 'active' => true]);

        $pending = PendingRegistration::create([
            'full_name' => 'Unverified Person',
            'member_name' => 'unverified',
            'member_email' => 'unverified@example.org',
            'password' => 'Str0ng-pass!x',
            'position' => 4,
            'email_verified_at' => null,
        ]);

        $this->actingAs($admin)
            ->post("/admin/users/pending/{$pending->id}/approve")
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('users', ['member_name' => 'unverified']);
    }

    /**
     * Same finding, second half: passwordExpired() returns false whenever pass_exp_date is
     * null, so the 90-day expiry never fired for ANY approved account — which is every
     * account except the console-created bootstrap admin.
     */
    public function test_an_approved_account_gets_a_password_expiry_date(): void
    {
        $this->seedAccess();
        $admin = User::factory()->create(['position' => 0, 'active' => true]);

        $pending = PendingRegistration::create([
            'full_name' => 'Verified Person',
            'member_name' => 'verified',
            'member_email' => 'verified@example.org',
            'password' => 'Str0ng-pass!x',
            'position' => 4,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)->post("/admin/users/pending/{$pending->id}/approve");

        $created = User::where('member_name', 'verified')->firstOrFail();

        $this->assertNotNull($created->pass_exp_date, 'the expiry backstop must be armed at approval');
        $this->assertNotNull($created->email_verified_at, 'verification must carry across to the account');
    }
}
