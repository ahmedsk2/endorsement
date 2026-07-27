<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where `back()` sends people.
 *
 * Three bugs reported as three separate things turned out to be one cause. `back()` asks
 * for the previous URL, which resolves from the `Referer` header first — deliberately
 * absent here, because `Referrer-Policy: no-referrer` keeps URLs that name a unit and a
 * date from travelling — and then from the session, which Laravel updates only on non-AJAX
 * GETs. Every Inertia visit is AJAX. So the pointer stayed pinned to whatever page was last
 * hard-loaded, for the whole session:
 *
 *   - saving on the profile sent the user to Admin → Settings;
 *   - and the signature preview, a plain `<img>` GET on a session route, overwrote the
 *     pointer with a PNG URL — after which every save redirected to an image and Inertia
 *     rendered the raw bytes over the whole viewport.
 */
class PreviousUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    /**
     * An Inertia visit, as the client actually makes it — XHR, with the asset version, or
     * the framework answers 409 "reload" instead of rendering the page.
     */
    private function inertiaHeaders(): array
    {
        // Asked of the middleware directly. Inertia::getVersion() only returns the real
        // asset version once a request has already run through it, so reading it up front
        // yields the default and the framework answers 409 on the first visit of a test.
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new \App\Http\Middleware\HandleInertiaRequests)->version(request()),
            'X-Requested-With' => 'XMLHttpRequest',
        ];
    }

    private function visit(User $user, string $uri): void
    {
        $this->actingAs($user)
            ->withHeaders($this->inertiaHeaders())
            ->get($uri)
            ->assertSuccessful();
    }

    public function test_saving_returns_to_the_page_you_were_on_not_the_last_hard_reload(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        // The hard load that used to pin the pointer for the rest of the session.
        $this->actingAs($admin)->get('/admin/settings')->assertOk();

        // Then move around the app the way the client does — as XHR.
        $this->visit($admin, '/profile');

        $this->actingAs($admin)->patch('/profile', [
            'full_name' => 'Dr Renamed',
            'member_name' => $admin->member_name,
            'member_email' => $admin->member_email,
        ])->assertRedirect('/profile');
    }

    public function test_loading_a_signature_image_is_not_recorded_as_a_page(): void
    {
        // The <img> on the profile: a real browser GET, no XHR headers, on a session route.
        $user = User::factory()->create(['position' => 4]);

        $this->visit($user, '/profile');
        $this->actingAs($user)->get('/signatures/me');   // 404 without one; still a route hit

        $this->actingAs($user)->patch('/profile', [
            'full_name' => $user->full_name,
            'member_name' => $user->member_name,
            'member_email' => $user->member_email,
        ])->assertRedirect('/profile');
    }

    public function test_a_redirecting_visit_is_not_recorded_as_a_destination(): void
    {
        // A page that bounces the user elsewhere is not somewhere to return to.
        $fresh = User::factory()->create(['position' => 4, 'setup_completed_at' => null]);

        $this->visit($fresh, '/setup');

        $this->actingAs($fresh)
            ->withHeaders($this->inertiaHeaders())
            ->get('/endorsement')
            ->assertRedirect('/setup');

        // The bounced-from URL must not have become "previous".
        $this->actingAs($fresh)->patch('/profile/reminders', ['unit_ids' => []])
            ->assertRedirect('/setup');
    }

    public function test_a_user_mid_setup_can_open_the_emailed_confirmation_link(): void
    {
        // The allowlist matched only the POST that sends the email, not the link inside it,
        // so the user could request a confirmation and then be bounced away from it —
        // needing a verified address to choose email codes, and unable to verify one.
        $fresh = User::factory()->create([
            'position' => 4,
            'setup_completed_at' => null,
            'email_verified_at' => null,
        ]);

        $url = \App\Http\Controllers\Auth\EmailVerificationController::accountUrl($fresh);

        $this->actingAs($fresh)->get($url)->assertRedirect();

        $this->assertNotNull($fresh->fresh()->email_verified_at);
    }
}
