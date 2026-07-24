<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The `cap:` middleware alias gates a route by a capability key: 200 when the user holds it,
 * 403 (plus a PHI-free `access_denied` audit row) when they do not.
 */
class EnsureCapabilityMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway route guarded by the middleware under test.
        Route::middleware(['web', 'auth', 'cap:access.manage'])
            ->get('/_test/control-admin', fn () => response('ok'));
    }

    public function test_admin_passes_the_capability_gate(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)
            ->get('/_test/control-admin')
            ->assertOk()
            ->assertSee('ok');
    }

    public function test_nurse_is_forbidden_and_an_access_denied_row_is_written(): void
    {
        $this->seed(AccessControlSeeder::class);
        $nurse = User::factory()->create(['position' => 1]);

        $this->actingAs($nurse)
            ->get('/_test/control-admin')
            ->assertForbidden();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'access_denied',
            'user_id' => $nurse->id,
            'detail' => 'cap=access.manage',
        ]);
    }

    public function test_no_access_denied_row_is_written_on_success(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)->get('/_test/control-admin')->assertOk();

        $this->assertDatabaseMissing('audit_log', ['action' => 'access_denied']);
    }
}
