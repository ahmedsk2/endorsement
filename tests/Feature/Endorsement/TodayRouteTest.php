<?php

namespace Tests\Feature\Endorsement;

use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7.1 — one-tap access. The installed PWA opens at /endorsement/today, which lands
 * on the CURRENT date's sheet for the user's remembered unit; with no preference it falls
 * back to the chooser. Visiting a unit's sheet remembers that unit.
 */
class TodayRouteTest extends TestCase
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

    public function test_today_redirects_to_the_remembered_units_current_sheet(): void
    {
        $user = $this->editor();
        $nicu = Unit::where('code', 'NICU')->firstOrFail();
        $user->update(['preferred_unit_id' => $nicu->id]);

        $this->actingAs($user)
            ->get('/endorsement/today')
            ->assertRedirect('/endorsement/NICU/'.now()->format('Y-m-d'));
    }

    public function test_today_without_a_preference_falls_back_to_the_chooser(): void
    {
        $this->actingAs($this->editor())
            ->get('/endorsement/today')
            ->assertRedirect('/endorsement');
    }

    public function test_today_creates_nothing(): void
    {
        $user = $this->editor();
        $picu = Unit::where('code', 'PICU')->firstOrFail();
        $user->update(['preferred_unit_id' => $picu->id]);

        $this->actingAs($user)->get('/endorsement/today');

        $this->assertDatabaseCount('handovers', 0);
    }

    public function test_visiting_a_unit_sheet_remembers_the_unit(): void
    {
        // An IN-APP visit. The remembered unit is a state change performed by a GET, so it
        // is conditioned on the Inertia header now that the session cookie is SameSite=lax:
        // a top-level cross-site GET carries the cookie under lax, and a stranger's link
        // must not be able to move a clinician's preference. Normal use is unaffected —
        // reaching a unit sheet means clicking through the app.
        $user = $this->editor();

        $this->actingAs($user)->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new \App\Http\Middleware\HandleInertiaRequests)->version(request()),
            'X-Requested-With' => 'XMLHttpRequest',
        ])->get('/endorsement/scbu/2026-07-10')->assertSuccessful();

        $scbu = Unit::where('code', 'SCBU')->firstOrFail();
        $this->assertSame($scbu->id, $user->fresh()->preferred_unit_id);
    }

    public function test_today_requires_authentication(): void
    {
        $this->get('/endorsement/today')->assertRedirect('/login');
    }
}
