<?php

namespace Tests\Feature\Units;

use App\Models\Unit;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Design §14 item 6. `routes/web.php` declares `/endorsement/today`, `/endorsement/compliance`
 * and `/endorsement/rows/{handover}` BEFORE `/endorsement/{unit}` so those literal segments
 * never bind as a unit code — a trick that works only while the unit registry is hardcoded. A
 * unit created with one of these codes would be permanently route-shadowed and unreachable, with
 * no error anywhere. P0a made units configuration; this guard lands before the P1 admin UI that
 * could otherwise create one.
 */
class ReservedUnitCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reserved_code_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Unit::create(['code' => 'TODAY', 'name' => 'Shadowed Unit']);
    }

    /** The code mutator normalizes (uppercase, trim) before the guard sees it. */
    public function test_the_guard_applies_after_normalization(): void
    {
        foreach (['today', ' today ', 'Today'] as $variant) {
            try {
                Unit::create(['code' => $variant, 'name' => 'Shadowed Unit']);
                $this->fail("expected [{$variant}] to be refused as a reserved code");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('TODAY', $e->getMessage());
            }
        }
    }

    public function test_compliance_and_rows_are_also_refused(): void
    {
        foreach (['COMPLIANCE', 'ROWS'] as $code) {
            try {
                Unit::create(['code' => $code, 'name' => 'Shadowed Unit']);
                $this->fail("expected [{$code}] to be refused as a reserved code");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($code, $e->getMessage());
            }
        }
    }

    public function test_a_non_reserved_code_still_creates_normally(): void
    {
        $unit = Unit::create(['code' => 'RGH1', 'name' => 'Riyadh General Ward 1']);
        $this->assertTrue($unit->exists);

        $unit2 = Unit::create(['code' => 'PICU2', 'name' => 'Second PICU']);
        $this->assertTrue($unit2->exists);
    }

    public function test_reference_seeder_still_seeds_its_four_units(): void
    {
        $this->seed(ReferenceSeeder::class);

        $this->assertSame(4, Unit::count());
        foreach (['PICU', 'NICU', 'SCBU', 'WARD'] as $code) {
            $this->assertDatabaseHas('units', ['code' => $code]);
        }
    }

    /**
     * The reserved list is not a hand-maintained opinion — it is the set of literal first
     * segments already declared under /endorsement. A new literal route added without extending
     * RESERVED_CODES silently makes a unit code unroutable, and this is the only thing that
     * would notice.
     */
    public function test_the_reserved_list_covers_every_literal_route_segment(): void
    {
        $literals = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter(fn ($uri) => str_starts_with($uri, 'endorsement/'))
            ->map(fn ($uri) => explode('/', $uri)[1])
            ->reject(fn ($seg) => str_starts_with($seg, '{'))
            ->map(fn ($seg) => strtoupper($seg))
            ->unique()->values()->all();

        $this->assertEqualsCanonicalizing($literals, Unit::RESERVED_CODES);
    }
}
