<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Structure → Units (cap:structure.manage). Munawib UN-01…05.
 *
 * The surface `Unit::RESERVED_CODES` was written for (Unit.php's own docblock says so): a code
 * that would be route-shadowed under /endorsement is refused here as a VALIDATION message, not
 * as the raw InvalidArgumentException the model guard throws.
 *
 * INACTIVE units are listed. UN-04 deactivation "hides forward, never deletes" — an
 * administrator who cannot see a retired unit cannot bring it back.
 *
 * Read-only in P1b Task 2 — the capability, the route and the nav entry had to land together,
 * and a nav entry pointing at a 404 is worse than no nav entry. Task 4 adds the write forms.
 */
class UnitController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Units', [
            'units' => Unit::query()->ordered()->get()->map(self::present(...))->values()->all(),
            // Offered and validated from ONE list (Unit::BAR_CLASSES) — the SignoffPickers
            // discipline applied to a much smaller thing.
            'palette' => Unit::BAR_CLASSES,
            // Surfaced so the form can warn BEFORE submit as well as refuse on it.
            'reserved_codes' => Unit::RESERVED_CODES,
        ]);
    }

    /**
     * The unit shape the screen edits. Deliberately NOT UnitProfile::toArray() — that is the
     * clinical sheet's contract and carries no administrative fields; this one carries no
     * print labels. Two audiences, two projections.
     *
     * @return array<string, mixed>
     */
    private static function present(Unit $unit): array
    {
        return [
            'id' => (int) $unit->getKey(),
            'code' => (string) $unit->code,
            'name' => (string) $unit->name,
            'name2' => $unit->name2,
            'display_order' => (int) $unit->display_order,
            'active' => (bool) $unit->active,
            'training_rotation' => (bool) $unit->training_rotation,
            'call_target' => (bool) $unit->call_target,
            'clinic_owner' => (bool) $unit->clinic_owner,
            'aliases' => $unit->aliases,
            'bar_class' => $unit->bar_class ?? 'channel-bar-slate',
        ];
    }
}
