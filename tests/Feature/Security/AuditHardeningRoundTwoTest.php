<?php

namespace Tests\Feature\Security;

use App\Casts\SanitizedHtml;
use App\Models\Handover;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Second batch of 2026-07-26 audit findings, confirmed in source before fixing.
 */
class AuditHardeningRoundTwoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SPC-DATABASE-005. The cast sanitises on WRITE and decrypts on READ, with a
     * plaintext fallback so one bad row cannot take out a whole sheet. But a value that
     * fails to decrypt is by definition one that never went through set() — so it has
     * never been purified either, and the fallback handed it to the browser raw.
     */
    public function test_an_unencrypted_legacy_value_is_sanitised_on_the_way_out(): void
    {
        $cast = new SanitizedHtml();
        $model = new Handover();

        $dirty = '<p>Septic shock</p><script>alert(document.cookie)</script>';

        $out = (string) $cast->get($model, 'details', $dirty, []);

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('Septic shock', $out, 'the clinical text itself must survive');
    }

    public function test_a_normally_written_value_still_round_trips(): void
    {
        $cast = new SanitizedHtml();
        $model = new Handover();

        $stored = $cast->set($model, 'details', '<p>On <b>noradrenaline</b></p>', []);
        $out = (string) $cast->get($model, 'details', $stored, []);

        $this->assertStringContainsString('noradrenaline', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    /**
     * SPC-API-003. `endpoint` was validated as a bare string, and the reminder scheduler
     * POSTs to it server-side — a blind SSRF with a boolean oracle, since the row is
     * deleted on a 404/410 response.
     */
    public function test_a_push_endpoint_must_be_an_https_url_from_a_known_push_service(): void
    {
        $this->seed(\Database\Seeders\ReferenceSeeder::class);
        $this->seed(\Database\Seeders\AccessControlSeeder::class);
        $user = User::factory()->create(['position' => 4, 'active' => true]);

        $rejected = [
            'http://169.254.169.254/latest/meta-data/',   // cloud metadata
            'http://127.0.0.1:3306/',                     // loopback port probe
            'https://attacker.example/collect',           // not a push service
            'not-a-url-at-all',
        ];

        foreach ($rejected as $endpoint) {
            $this->actingAs($user)
                ->post('/push/subscriptions', [
                    'endpoint' => $endpoint,
                    'keys' => ['p256dh' => 'k', 'auth' => 'a'],
                ])
                ->assertSessionHasErrors('endpoint');
        }

        $this->assertSame(0, DB::table('push_subscriptions')->count());
    }

    public function test_a_genuine_push_service_endpoint_is_accepted(): void
    {
        $this->seed(\Database\Seeders\ReferenceSeeder::class);
        $this->seed(\Database\Seeders\AccessControlSeeder::class);
        $user = User::factory()->create(['position' => 4, 'active' => true]);

        $this->actingAs($user)
            ->post('/push/subscriptions', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
                'keys' => ['p256dh' => 'k', 'auth' => 'a'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('push_subscriptions')->count());
    }
}
