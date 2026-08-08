<?php

namespace Tests\Feature\Endorsement;

use App\Http\Controllers\EndorsementController;
use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionMethod;
use Tests\TestCase;

/**
 * WHOSE handwritten signature may be printed on a handover sheet — owner ruling, 2026-07-27.
 *
 * A signed sheet is medico-legal evidence, and the signature image on it is the strongest
 * claim it makes. Until this ruling, ANY user who could edit could name a colleague and
 * freeze that colleague's signature onto the sheet — so a printed handover could carry a
 * clinician's handwriting for a shift they never attended, applied by someone else, at
 * 03:00, with nothing on the page indicating it.
 *
 * The owner's ruling:
 *
 *   - ADMIN (0) and CHIEF RESIDENT (5) may name any active resident, and that person's
 *     signature is applied. These are the roles that complete records on others' behalf,
 *     and the ward needs a route to a fully-signed sheet.
 *   - EVERYONE ELSE may still NAME a colleague — a handover has two people and the sheet
 *     must say so — but only their OWN signature image is ever applied. The colleague
 *     appears as a typed name.
 *
 * Two supporting rules, which are not the ruling but are the same defect:
 *
 *   - A signature is frozen only on the request that actually SIGNS the day. A draft save
 *     used to freeze one, so an unsigned sheet could carry an image.
 *   - An unsigned day never emits a signature URL at all. Withholding it in the template
 *     is not enough; the URL must not leave the server.
 */
class SignatureAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);

        Handover::create([
            'unit_id' => Unit::where('code', 'PICU')->firstOrFail()->id,
            'handover_date' => '2026-07-10',
            'bed' => '1',
            'mrn' => 'M-00001',
            'patient_name' => 'Test Child',
        ]);
    }

    /**
     * A clinician who has uploaded a signature.
     *
     * The path must be genuinely content-addressed — 64 hex characters. signatureUrl()
     * refuses anything else, so a friendlier fixture name makes every assertion about a
     * withheld URL pass no matter what the code does.
     */
    private function signer(int $position, string $name): User
    {
        return User::factory()->create([
            'position' => $position,
            'full_name' => $name,
            'signature_path' => 'signatures/'.hash('sha256', $name).'.png',
        ]);
    }

    private function signoff(): HandoverSignoff
    {
        return HandoverSignoff::firstOrFail();
    }

    public function test_a_resident_naming_a_colleague_does_not_apply_that_colleagues_signature(): void
    {
        $alpha = $this->signer(4, 'Dr Alpha');
        $beta = $this->signer(4, 'Dr Beta');

        $this->actingAs($alpha)->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $alpha->person_id,
            'endorsed_to_person_id' => $beta->person_id,
            'sign_off' => true,
        ])->assertRedirect();

        $s = $this->signoff();

        // Named, so the sheet still records the handover between two people...
        $this->assertSame('Dr Beta', $s->endorsed_to_name);
        // ...but Dr Beta's handwriting is not applied by Dr Alpha.
        $this->assertNull($s->endorsed_to_signature_path);
        // The actor's own signature is applied.
        $this->assertSame($alpha->signature_path, $s->endorsed_by_signature_path);
    }

    public function test_an_admin_may_apply_any_residents_signature(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $alpha = $this->signer(4, 'Dr Alpha');
        $beta = $this->signer(4, 'Dr Beta');

        $this->actingAs($admin)->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $alpha->person_id,
            'endorsed_to_person_id' => $beta->person_id,
            'sign_off' => true,
        ])->assertRedirect();

        $s = $this->signoff();

        $this->assertSame($alpha->signature_path, $s->endorsed_by_signature_path);
        $this->assertSame($beta->signature_path, $s->endorsed_to_signature_path);
    }

    public function test_a_chief_resident_may_apply_any_residents_signature(): void
    {
        $chief = User::factory()->create(['position' => 5]);
        $alpha = $this->signer(4, 'Dr Alpha');
        $beta = $this->signer(4, 'Dr Beta');

        $this->actingAs($chief)->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $alpha->person_id,
            'endorsed_to_person_id' => $beta->person_id,
            'sign_off' => true,
        ])->assertRedirect();

        $s = $this->signoff();

        $this->assertSame($alpha->signature_path, $s->endorsed_by_signature_path);
        $this->assertSame($beta->signature_path, $s->endorsed_to_signature_path);
    }

    public function test_a_consultant_may_not_apply_a_residents_signature(): void
    {
        // Position 3 can edit and can sign the day, but is not one of the two roles the
        // owner named. Naming a resident records the name, not the handwriting.
        $consultant = User::factory()->create(['position' => 3]);
        $alpha = $this->signer(4, 'Dr Alpha');

        $this->actingAs($consultant)->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $alpha->person_id,
            'sign_off' => true,
        ])->assertRedirect();

        $this->assertSame('Dr Alpha', $this->signoff()->endorsed_by_name);
        $this->assertNull($this->signoff()->endorsed_by_signature_path);
    }

    public function test_a_draft_save_never_freezes_a_signature(): void
    {
        // The day is not attested yet, so nothing on it may carry handwriting — not even
        // the drafter's own.
        $alpha = $this->signer(4, 'Dr Alpha');

        $this->actingAs($alpha)->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $alpha->person_id,
        ])->assertRedirect();

        $s = $this->signoff();

        $this->assertNull($s->signed_off_at);
        $this->assertSame('Dr Alpha', $s->endorsed_by_name);
        $this->assertNull($s->endorsed_by_signature_path);
    }

    public function test_an_unsigned_day_never_sends_a_signature_url_to_the_page(): void
    {
        $alpha = $this->signer(4, 'Dr Alpha');

        // A stored path from before this rule existed must still not be served for a day
        // that carries no attestation.
        HandoverSignoff::create([
            'unit_id' => Unit::where('code', 'PICU')->firstOrFail()->id,
            'handover_date' => '2026-07-10',
            'endorsed_by_person_id' => $alpha->person_id,
            'endorsed_by_name' => 'Dr Alpha',
            'endorsed_by_signature_path' => $alpha->signature_path,
        ]);

        $this->actingAs($alpha)->get('/endorsement/PICU/2026-07-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('signoff.signed_off', false)
                ->where('signoff.endorsed_by_signature', null)
                ->where('signoff.endorsed_by_name', 'Dr Alpha'),
            );
    }

    public function test_the_signing_clinicians_name_survives_their_account_being_deactivated(): void
    {
        // Wherever a signature is withheld, this line is the entire attestation of who
        // documented the handover — so it must behave like the endorser names, which were
        // frozen from the start. It was resolved live through a belongsTo against a
        // SoftDeletes model, so deactivating an account (which this system does instead of
        // deleting) silently erased "Signed off … by …" from every historical print.
        $alpha = $this->signer(4, 'Dr Alpha');

        $this->actingAs($alpha)->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $alpha->person_id,
            'sign_off' => true,
        ])->assertRedirect();

        $viewer = User::factory()->create(['position' => 0]);
        $alpha->delete();

        $this->actingAs($viewer)->get('/endorsement/PICU/2026-07-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('signoff.signed_off_by_name', 'Dr Alpha'),
            );
    }

    public function test_the_signature_provenance_is_audited_without_naming_anyone(): void
    {
        // The printed sheet cannot distinguish "signature withheld because a colleague named
        // them" from "this clinician has never uploaded one". The trail can, and must do it
        // with field names only — no user names, no ids beyond the row.
        $alpha = $this->signer(4, 'Dr Alpha');
        $beta = $this->signer(4, 'Dr Beta');

        $this->actingAs($alpha)->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $alpha->person_id,
            'endorsed_to_person_id' => $beta->person_id,
            'sign_off' => true,
        ])->assertRedirect();

        $detail = (string) \App\Models\AuditLog::where('action', 'endorsement_signoff')
            ->orderByDesc('id')->value('detail');

        $this->assertStringContainsString('sig_by=self', $detail);
        $this->assertStringContainsString('sig_to=withheld', $detail);
        $this->assertStringNotContainsString('Alpha', $detail);
        $this->assertStringNotContainsString('Beta', $detail);
    }

    /**
     * D9 — a consultant may be a ROSTER-ONLY person, with no account at all. The name is still
     * frozen exactly as for a claimed consultant; there is nothing to sign because the consultant
     * fields carry no signature columns to begin with.
     */
    public function test_a_roster_only_consultant_is_accepted_and_named_with_no_signature_anywhere(): void
    {
        $alpha = $this->signer(4, 'Dr Alpha');
        $oncall = Person::factory()->create(['position' => 3, 'full_name' => 'Dr Roster Oncall']);

        $this->actingAs($alpha)->patch('/endorsement/PICU/2026-07-10/signoff', [
            'endorsed_by_person_id' => $alpha->person_id,
            'consultant_by_person_id' => $oncall->id,
            'sign_off' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $s = $this->signoff();

        $this->assertSame($oncall->id, $s->consultant_by_person_id);
        $this->assertSame('Dr Roster Oncall', $s->consultant_by_name);
        // The consultant fields carry no signature columns at all — proven directly on the
        // schema, not merely by asserting a value is null, which a renamed column could still pass.
        $this->assertArrayNotHasKey('consultant_by_signature_path', $s->getAttributes());
        $this->assertArrayNotHasKey('consultant_to_signature_path', $s->getAttributes());
    }

    /**
     * `resolveSignature()`'s `unclaimed` branch (P0c hazard 3): the named party is a PERSON, and
     * `SignatureStore` stays keyed on `users` — a person can be named without an account, but
     * signing requires one. D9's write-side rule already refuses an unclaimed endorser at
     * validation, so this branch is UNREACHABLE through the HTTP endpoint and is exercised
     * directly, as the defence-in-depth it is documented to be.
     *
     * Plan note: the plan describes constructing this "by deleting the account between draft and
     * sign" over two HTTP requests. Traced through updateSignoff(): the freeze loop only calls
     * resolveSignature() for a field present in THAT request's payload
     * (`array_key_exists($field.'_person_id', $data)`), and D9's rule re-validates on every
     * request that names the field — so a request that re-submits the now-unclaimed id is refused
     * before resolveSignature() runs, and a request that omits the field never calls it at all.
     * There is no two-request HTTP path that reaches this branch; reflection is the faithful way
     * to prove the line is correct, exactly as its own docblock admits.
     */
    public function test_resolve_signature_reports_unclaimed_for_a_person_with_no_account(): void
    {
        $actor = User::factory()->create(['position' => 0]);
        $rosterOnly = Person::factory()->create(['position' => 4, 'full_name' => 'Dr No Account']);

        $method = new ReflectionMethod(EndorsementController::class, 'resolveSignature');
        $method->setAccessible(true);

        [$path, $why] = $method->invoke(new EndorsementController, $actor, $rosterOnly, true);

        $this->assertNull($path);
        $this->assertSame('unclaimed', $why);
    }
}
