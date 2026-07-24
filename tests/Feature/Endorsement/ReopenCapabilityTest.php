<?php

namespace Tests\Feature\Endorsement;

use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\RoleCapability;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserCapability;
use App\Support\AccessControl;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Task 3(a) — the NARROW `endorsement.reopen` capability.
 *
 * Reopening a signed handover was gated on `control.admin`, which meant the only way to let a
 * senior clinician correct a wrong attestation at 03:00 was to hand them the entire administrator
 * console — user management, access control and all. That is a worse security outcome than the
 * problem it solves, and it left wards unable to self-correct outside admin hours.
 *
 * The fix is a DEDICATED capability with the same day-one behaviour (Administrator only) that the
 * owner can widen deliberately, per role or per named user, without granting anything else.
 * Nothing about the reversal itself is relaxed: the reason stays mandatory, the stamp stays, the
 * audit row stays PHI-free.
 */
class ReopenCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
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
            'patient_name' => 'Test Child',
        ]);
    }

    /** Sign the day as an ordinary editor (Charge Nurse), leaving it locked. */
    private function signDay(string $date): User
    {
        $editor = User::factory()->create(['position' => 2]);
        $by = User::factory()->create(['position' => 4]);

        $this->actingAs($editor)->patch("/endorsement/PICU/{$date}/signoff", [
            'endorsed_by_user_id' => $by->id,
            'sign_off' => true,
        ])->assertRedirect();

        return $editor;
    }

    /** Grant a capability to one named user (the per-user override path). */
    private function grant(User $user, string $key): void
    {
        UserCapability::create([
            'user_id' => $user->id,
            'capability_id' => Capability::where('key', $key)->firstOrFail()->id,
            'effect' => 'grant',
        ]);
        AccessControl::flush($user->id);
    }

    // ------------------------------------------------- the catalog entry

    /**
     * The capability must be a real, DESCRIBED catalog entry — an administrator granting it reads
     * the description on Admin → Access Control and has to be able to tell what it permits and why
     * it is separate from `endorsement.edit`.
     */
    public function test_the_capability_is_in_the_catalog_with_a_label_and_description(): void
    {
        $capability = Capability::where('key', 'endorsement.reopen')->first();

        $this->assertNotNull($capability, 'endorsement.reopen must exist in the capability catalog');
        $this->assertNotSame('', (string) $capability->label);
        $this->assertNotNull($capability->description);
        $this->assertStringContainsString('attestation', mb_strtolower((string) $capability->description));
    }

    /**
     * DAY-ONE PARITY. The capability changes who CAN be granted the power, never who holds it out
     * of the box: Administrator (0) alone, exactly as `control.admin` gated it before.
     */
    public function test_only_administrator_holds_it_by_default(): void
    {
        $capabilityId = Capability::where('key', 'endorsement.reopen')->firstOrFail()->id;

        $positions = RoleCapability::where('capability_id', $capabilityId)
            ->pluck('position')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([0], $positions);
    }

    // ------------------------------------------------- the gate itself

    public function test_an_editor_without_the_capability_still_cannot_reopen(): void
    {
        $this->handover('2026-07-10');
        $editor = $this->signDay('2026-07-10');

        $signedAt = HandoverSignoff::firstOrFail()->signed_off_at;

        $this->actingAs($editor)
            ->post('/endorsement/PICU/2026-07-10/signoff/reopen', ['reason' => 'Wrong receiving resident'])
            ->assertForbidden();

        $s = HandoverSignoff::firstOrFail();
        $this->assertEquals($signedAt, $s->signed_off_at);
        $this->assertNull($s->reopened_at);
    }

    /**
     * THE POINT OF THE WHOLE CHANGE — a senior clinician can be given correction rights WITHOUT the
     * administrator console. The grant is per named user and nothing else comes with it.
     */
    public function test_a_named_clinician_granted_the_capability_can_reopen_without_being_an_admin(): void
    {
        $this->handover('2026-07-10');
        $this->signDay('2026-07-10');

        $consultant = User::factory()->create(['position' => 3, 'full_name' => 'Dr Senior']);
        $this->grant($consultant, 'endorsement.reopen');

        // The grant is narrow: it does NOT drag the admin console along with it.
        $this->assertFalse(AccessControl::allows($consultant->fresh(), 'control.admin'));
        $this->assertFalse(AccessControl::allows($consultant->fresh(), 'users.manage'));

        $this->actingAs($consultant)
            ->post('/endorsement/PICU/2026-07-10/signoff/reopen', ['reason' => 'Wrong receiving resident selected'])
            ->assertRedirect();

        $s = HandoverSignoff::firstOrFail();
        $this->assertNull($s->signed_off_at);
        $this->assertNotNull($s->reopened_at);
        $this->assertSame('Wrong receiving resident selected', $s->reopen_reason);
        $this->assertSame($consultant->id, $s->reopened_by_user_id);
    }

    /**
     * The mandatory reason survives the re-gating: a reopen with no reason is still refused, and
     * the audit row still carries ids only — never the free-text reason, which could name a child.
     */
    public function test_the_reason_stays_mandatory_and_the_audit_row_stays_phi_free(): void
    {
        $this->handover('2026-07-10');
        $this->signDay('2026-07-10');

        $consultant = User::factory()->create(['position' => 3]);
        $this->grant($consultant, 'endorsement.reopen');

        $this->actingAs($consultant)
            ->post('/endorsement/PICU/2026-07-10/signoff/reopen', [])
            ->assertSessionHasErrors('reason');

        $this->assertTrue(HandoverSignoff::firstOrFail()->isSignedOff());

        $this->actingAs($consultant)
            ->post('/endorsement/PICU/2026-07-10/signoff/reopen', ['reason' => 'Baby Ahmed was mis-endorsed'])
            ->assertRedirect();

        $audit = AuditLog::where('action', 'endorsement_signoff_reopen')->firstOrFail();
        $this->assertStringNotContainsString('Ahmed', (string) $audit->detail);
        $this->assertStringContainsString('unit=', (string) $audit->detail);
    }

    /** Deny always wins, including over a role-level grant of the new capability. */
    public function test_a_per_user_deny_beats_a_role_grant(): void
    {
        $this->handover('2026-07-10');
        $this->signDay('2026-07-10');

        $capabilityId = Capability::where('key', 'endorsement.reopen')->firstOrFail()->id;
        RoleCapability::firstOrCreate(['position' => 3, 'capability_id' => $capabilityId]);
        AccessControl::flush();

        $consultant = User::factory()->create(['position' => 3]);
        UserCapability::create([
            'user_id' => $consultant->id,
            'capability_id' => $capabilityId,
            'effect' => 'deny',
        ]);
        AccessControl::flush($consultant->id);

        $this->actingAs($consultant)
            ->post('/endorsement/PICU/2026-07-10/signoff/reopen', ['reason' => 'Attempted correction'])
            ->assertForbidden();

        $this->assertTrue(HandoverSignoff::firstOrFail()->isSignedOff());
    }

    // ------------------------------------------------- who to contact

    /**
     * The refusal must name people who can ACTUALLY act. Naming "an Administrator" was already a
     * half-truth once the power became grantable — a ward would ring an admin who no longer holds
     * it, or fail to ring the consultant down the corridor who does.
     */
    public function test_the_contacts_are_the_actual_capability_holders_not_a_hard_coded_role(): void
    {
        $this->handover('2026-07-10');
        $editor = $this->signDay('2026-07-10');

        $admin = User::factory()->create(['position' => 0, 'full_name' => 'Aa Admin']);
        $holder = User::factory()->create(['position' => 3, 'full_name' => 'Bb Consultant Holder']);
        $this->grant($holder, 'endorsement.reopen');

        // A consultant who was NOT granted it must not be advertised as someone who can help.
        User::factory()->create(['position' => 3, 'full_name' => 'Cc Consultant Nonholder']);

        // An administrator explicitly DENIED the capability can no longer reopen, so naming them
        // would send the ward to a dead end.
        $deniedAdmin = User::factory()->create(['position' => 0, 'full_name' => 'Dd Denied Admin']);
        UserCapability::create([
            'user_id' => $deniedAdmin->id,
            'capability_id' => Capability::where('key', 'endorsement.reopen')->firstOrFail()->id,
            'effect' => 'deny',
        ]);
        AccessControl::flush($deniedAdmin->id);

        $response = $this->actingAs($editor)
            ->post('/endorsement/PICU/2026-07-10/signoff/reopen', ['reason' => 'Wrong receiving resident']);

        $response->assertForbidden();
        $body = $response->getContent();

        $this->assertStringContainsString('Aa Admin', $body);
        $this->assertStringContainsString('Bb Consultant Holder', $body);
        $this->assertStringNotContainsString('Cc Consultant Nonholder', $body);
        $this->assertStringNotContainsString('Dd Denied Admin', $body);
    }

    /** The sheet tells a non-holder BEFORE they try, with the same accurate contact list. */
    public function test_the_sheet_discloses_reopen_rights_and_contacts_up_front(): void
    {
        $this->handover('2026-07-10');
        $editor = $this->signDay('2026-07-10');

        $holder = User::factory()->create(['position' => 3, 'full_name' => 'Bb Consultant Holder']);
        $this->grant($holder, 'endorsement.reopen');

        $this->actingAs($editor)
            ->get('/endorsement/PICU/2026-07-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('signoff.can_reopen', false)
                ->where('signoff.reopen_contacts', ['Bb Consultant Holder'])
                ->etc()
            );

        $this->actingAs($holder)
            ->get('/endorsement/PICU/2026-07-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('signoff.can_reopen', true)
                ->where('signoff.reopen_contacts', [])
                ->etc()
            );
    }

    /** An INACTIVE holder is never advertised — the account cannot log in to act. */
    public function test_an_inactive_holder_is_not_offered_as_a_contact(): void
    {
        $this->handover('2026-07-10');
        $editor = $this->signDay('2026-07-10');

        $inactive = User::factory()->create([
            'position' => 0,
            'full_name' => 'Ee Retired Admin',
            'active' => false,
        ]);
        $this->assertNotNull($inactive->id);

        User::factory()->create(['position' => 0, 'full_name' => 'Ff Working Admin']);

        $response = $this->actingAs($editor)
            ->post('/endorsement/PICU/2026-07-10/signoff/reopen', ['reason' => 'Wrong receiving resident']);

        $response->assertForbidden();
        $this->assertStringNotContainsString('Ee Retired Admin', $response->getContent());
        $this->assertStringContainsString('Ff Working Admin', $response->getContent());
    }
}
