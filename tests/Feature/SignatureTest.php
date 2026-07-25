<?php

namespace Tests\Feature;

use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Handwritten signatures: stored privately and IMMUTABLY, snapshotted at sign-off, and
 * printed beside the endorser's name on the signed sheet.
 */
class SignatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
        Storage::fake('local');
    }

    /** A tiny real PNG, as a canvas would produce. */
    private function pngDataUrl(int $shade = 0): string
    {
        $image = imagecreatetruecolor(60, 20);
        imagefilledrectangle($image, 0, 0, 59, 19, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 2, 15, 55, 4 + $shade, imagecolorallocate($image, $shade, $shade, $shade));
        ob_start();
        imagepng($image);
        $raw = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($raw);
    }

    public function test_a_drawn_signature_is_stored_privately_and_never_in_public(): void
    {
        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($user)
            ->post('/profile/signature', ['signature_data' => $this->pngDataUrl()])
            ->assertRedirect()->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue($user->hasSignature());
        // Content-addressed under the private signatures/ prefix.
        $this->assertMatchesRegularExpression('#^signatures/[a-f0-9]{64}\.png$#', $user->signature_path);
        Storage::disk('local')->assertExists($user->signature_path);
    }

    public function test_an_uploaded_image_is_re_encoded_and_a_non_image_is_refused(): void
    {
        $user = User::factory()->create(['position' => 4]);

        $png = base64_decode(explode(',', $this->pngDataUrl())[1]);
        $file = UploadedFile::fake()->createWithContent('sig.png', $png);

        $this->actingAs($user)->post('/profile/signature', ['signature_file' => $file])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->hasSignature());

        // A file that merely claims to be a PNG dies at the re-encode.
        $evil = UploadedFile::fake()->createWithContent('evil.png', '<?php echo "pwned"; ?>');
        $this->actingAs($user)->post('/profile/signature', ['signature_file' => $evil])
            ->assertSessionHasErrors();
    }

    public function test_changing_a_signature_writes_a_new_file_and_never_overwrites_the_old(): void
    {
        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($user)->post('/profile/signature', ['signature_data' => $this->pngDataUrl(0)]);
        $first = $user->fresh()->signature_path;

        $this->actingAs($user)->post('/profile/signature', ['signature_data' => $this->pngDataUrl(40)]);
        $second = $user->fresh()->signature_path;

        $this->assertNotSame($first, $second);
        // The old image is still on disk — an already-signed sheet still points at it.
        Storage::disk('local')->assertExists($first);
        Storage::disk('local')->assertExists($second);
    }

    public function test_signature_images_require_authentication_and_the_endorsement_gate(): void
    {
        // Stored directly, so this test never establishes an authenticated session
        // before the anonymous assertion below.
        $owner = User::factory()->create(['position' => 4]);
        $path = \App\Support\SignatureStore::putDataUrl($this->pngDataUrl());
        $owner->forceFill(['signature_path' => $path, 'signature_updated_at' => now()])->save();
        $hash = str_replace(['signatures/', '.png'], '', $path);

        // Anonymous: no.
        $this->get('/signatures/file/'.$hash)->assertRedirect('/login');

        // Signed in WITHOUT endorsement.view (a retired-role account): no.
        $outsider = User::factory()->create(['position' => 1]);
        $this->actingAs($outsider)->get('/signatures/file/'.$hash)->assertForbidden();

        // A colleague who reads handovers: yes.
        $colleague = User::factory()->create(['position' => 3]);
        $this->actingAs($colleague)->get('/signatures/file/'.$hash)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_a_path_outside_the_signature_store_cannot_be_requested(): void
    {
        $user = User::factory()->create(['position' => 3]);

        foreach (['../../.env', 'not-a-hash', str_repeat('z', 64)] as $attempt) {
            $this->actingAs($user)->get('/signatures/file/'.urlencode($attempt))->assertNotFound();
        }
    }

    public function test_signing_freezes_the_signature_and_the_print_sheet_shows_it(): void
    {
        $editor = User::factory()->create(['position' => 2]);
        $resident = User::factory()->create(['position' => 4, 'full_name' => 'Dr Sign']);

        $this->actingAs($resident)->post('/profile/signature', ['signature_data' => $this->pngDataUrl()]);
        $atSigning = $resident->fresh()->signature_path;

        $unit = Unit::where('code', 'PICU')->firstOrFail();
        Handover::create(['unit_id' => $unit->id, 'handover_date' => '2026-07-20', 'mrn' => 'S-1']);

        $this->actingAs($editor)->patch('/endorsement/PICU/2026-07-20/signoff', [
            'endorsed_by_user_id' => $resident->id,
            'sign_off' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $signoff = HandoverSignoff::firstOrFail();
        $this->assertSame($atSigning, $signoff->endorsed_by_signature_path);

        // The print payload carries the frozen image, addressed by content hash.
        $expected = '/signatures/file/'.str_replace(['signatures/', '.png'], '', $atSigning);

        $this->actingAs($editor)
            ->get('/endorsement/PICU/2026-07-20/print')
            ->assertInertia(fn (Assert $page) => $page
                ->where('signoff.endorsed_by_name', 'Dr Sign')
                ->where('signoff.endorsed_by_signature', $expected)
            );

        // The resident redraws their signature — the SIGNED sheet must not change.
        $this->actingAs($resident)->post('/profile/signature', ['signature_data' => $this->pngDataUrl(40)]);
        $this->assertSame($atSigning, $signoff->fresh()->endorsed_by_signature_path);
    }

    public function test_removing_a_signature_leaves_already_signed_sheets_intact(): void
    {
        $editor = User::factory()->create(['position' => 2]);
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->post('/profile/signature', ['signature_data' => $this->pngDataUrl()]);
        $path = $resident->fresh()->signature_path;

        $unit = Unit::where('code', 'NICU')->firstOrFail();
        Handover::create(['unit_id' => $unit->id, 'handover_date' => '2026-07-20', 'mrn' => 'S-2']);
        $this->actingAs($editor)->patch('/endorsement/NICU/2026-07-20/signoff', [
            'endorsed_by_user_id' => $resident->id,
            'sign_off' => true,
        ]);

        $this->actingAs($resident)->delete('/profile/signature')->assertRedirect();

        $this->assertFalse($resident->fresh()->hasSignature());
        $this->assertSame($path, HandoverSignoff::firstOrFail()->endorsed_by_signature_path);
    }

    public function test_the_chooser_nags_about_an_incomplete_setup(): void
    {
        $user = User::factory()->create(['position' => 4, 'email_verified_at' => null]);

        $this->actingAs($user)
            ->get('/endorsement')
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.complete', false)
                ->where('setup.email_verified', false)
                ->where('setup.two_factor', false)
                ->where('setup.has_signature', false)
            );

        // Complete the checklist -> the nag goes away.
        $user->forceFill([
            'email_verified_at' => now(),
            'two_factor_method' => 'email',
        ])->save();
        $this->actingAs($user)->post('/profile/signature', ['signature_data' => $this->pngDataUrl()]);

        $this->actingAs($user->fresh())
            ->get('/endorsement')
            ->assertInertia(fn (Assert $page) => $page->where('setup.complete', true));
    }
}
