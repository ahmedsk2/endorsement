<?php

namespace Tests\Feature\Security;

use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SameSite=lax, and the one thing that had leaned on strict.
 *
 * `strict` withholds the session cookie on any navigation this site did not initiate. On a
 * desktop bookmark that never happens; on a phone it is most of the ways in — an installed
 * PWA has its own cookie jar, a notification tap is not a same-site click, and invitation,
 * password-reset and email-confirmation links all arrive in a mail app. Each arrived
 * cookie-less, was treated as anonymous and bounced to /login, which is what "it keeps
 * logging me off" meant on the ward.
 *
 * `lax` still withholds the cookie on cross-site POST/PATCH/DELETE, and the CSRF token
 * remains the real control on every write — so this is a correction, not a relaxation.
 * What DID lean on strict was a state change performed by a GET, which is now conditioned
 * on an in-app navigation.
 */
class SessionCookiePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    public function test_the_session_cookie_is_lax_not_strict(): void
    {
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_a_cross_site_get_cannot_change_the_remembered_unit(): void
    {
        // Under lax the session cookie DOES ride along on a top-level cross-site GET, so a
        // stranger's link must not be able to move a clinician's preference. A plain
        // navigation carries no Inertia header; only an in-app visit does.
        $user = User::factory()->create(['position' => 4]);
        $nicu = Unit::where('code', 'NICU')->firstOrFail();

        $this->actingAs($user)->get('/endorsement/NICU/2026-07-25')->assertOk();

        $this->assertNotSame($nicu->id, $user->fresh()->preferred_unit_id);
    }

    public function test_an_in_app_visit_still_remembers_the_unit(): void
    {
        $user = User::factory()->create(['position' => 4]);
        $nicu = Unit::where('code', 'NICU')->firstOrFail();

        $this->actingAs($user)->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new \App\Http\Middleware\HandleInertiaRequests)->version(request()),
            'X-Requested-With' => 'XMLHttpRequest',
        ])->get('/endorsement/NICU/2026-07-25')->assertSuccessful();

        $this->assertSame($nicu->id, $user->fresh()->preferred_unit_id);
    }

    public function test_csrf_validation_is_still_in_the_web_stack(): void
    {
        // The control lax leaves doing the real work on writes. Its BEHAVIOUR cannot be
        // exercised here — ValidateCsrfToken deliberately no-ops under runningUnitTests —
        // so assert the wiring instead: it must still be in the group. Removing it would
        // make the SameSite change genuinely load-bearing, which it is not today.
        $stack = app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['web'];

        $this->assertContains(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, $stack);
    }
}
