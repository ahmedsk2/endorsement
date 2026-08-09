<?php

namespace Tests\Feature\Units;

use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE.md's two pending exceptions, closed: `AppLayout.vue` hardcoded a four-entry unit array
 * and `app.css` defined exactly four unit hues, so a fifth department created through P1b's new
 * units screen would have had no sidebar entry and no colour.
 *
 * The nav's unit list is now a SHARED INERTIA PROP built from `Unit::codes()`' own source of
 * truth, so a unit created, renamed, recoloured, reordered or retired is reflected without a
 * frontend change.
 */
class NavUnitsAreConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    public function test_the_shared_prop_carries_the_four_seeded_units_in_display_order(): void
    {
        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($user)->get('/endorsement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('nav.units', 4)
                ->where('nav.units.0', [
                    'code' => 'picu',
                    'label' => 'Pediatric Intensive Care Unit',
                    'bar' => 'channel-bar-picu',
                ])
                ->where('nav.units.3.code', 'ward')
            );
    }

    public function test_a_fifth_unit_appears_in_the_nav_without_a_frontend_change(): void
    {
        Unit::create([
            'code' => 'RGH1', 'name' => 'Riyadh General Ward 1', 'active' => true,
            'display_order' => 5, 'bar_class' => 'channel-bar-amber',
        ]);

        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($user)->get('/endorsement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('nav.units', 5)
                ->where('nav.units.4', [
                    'code' => 'rgh1',
                    'label' => 'Riyadh General Ward 1',
                    'bar' => 'channel-bar-amber',
                ])
            );
    }

    /** UN-04: deactivation hides FORWARD. A retired unit leaves the nav; its history stays. */
    public function test_a_retired_unit_leaves_the_nav(): void
    {
        Unit::findByCode('SCBU')->update(['active' => false]);

        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($user)->get('/endorsement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('nav.units', 3)
                ->where('nav.units.2.code', 'ward')
            );
    }

    /** A unit with no stored bar_class still gets a colour, never an empty class attribute. */
    public function test_a_unit_without_a_stored_colour_falls_back_to_a_palette_entry(): void
    {
        Unit::create(['code' => 'RGH2', 'name' => 'Ward 2', 'active' => true, 'display_order' => 6]);

        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($user)->get('/endorsement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('nav.units.4.bar', 'channel-bar-slate')
            );
    }

    public function test_a_guest_gets_an_empty_unit_list_not_an_error(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('nav.units', []));
    }

    /** Every palette class the model offers has a rule in the stylesheet, and vice versa. */
    public function test_the_palette_and_the_stylesheet_agree(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        foreach (array_keys(Unit::BAR_CLASSES) as $class) {
            $this->assertStringContainsString(
                '.'.$class.' {',
                $css,
                "Unit::BAR_CLASSES offers [{$class}] but resources/css/app.css defines no rule for it"
            );
        }

        preg_match_all('/\.(channel-bar-[a-z0-9]+) \{/', $css, $matches);
        $declared = array_values(array_diff($matches[1], ['channel-bar-ok', 'channel-bar-critical', 'channel-bar-caution']));

        $this->assertEqualsCanonicalizing(array_keys(Unit::BAR_CLASSES), $declared);
    }
}
