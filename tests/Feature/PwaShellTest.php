<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §10.1 — the PWA shell. The manifest makes the app installable and opens it at
 * /endorsement/today; the service worker caches the APP SHELL ONLY. Clinical data is
 * never cached: every /endorsement response says so explicitly (belt-and-braces against
 * any service worker or proxy caching a census).
 */
class PwaShellTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Static assets are served by the web server, not the framework, so these assert on
     * the artifacts themselves (the deploy contract), not on an HTTP round-trip.
     */
    public function test_the_manifest_exists_and_starts_at_today(): void
    {
        $path = public_path('manifest.webmanifest');
        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true);

        $this->assertSame('/endorsement/today', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertNotEmpty($manifest['icons']);
        $this->assertStringContainsString('manifest.webmanifest', (string) file_get_contents(resource_path('views/app.blade.php')));
    }

    public function test_the_service_worker_exists_and_keeps_the_shell_only_doctrine(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        // The shell-only doctrine, greppable in the artifact itself.
        $this->assertStringContainsString('never cache /endorsement', $sw);
        // Navigations are network-first — a cached census must be impossible.
        $this->assertStringContainsString("mode === 'navigate'", $sw);
        $this->assertFileExists(public_path('offline.html'));
    }

    public function test_endorsement_responses_are_never_cacheable(): void
    {
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create(['position' => 2]);

        $response = $this->actingAs($user)->get('/endorsement/PICU');

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
