<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UnitRequest;
use App\Models\AuditLog;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 * There is deliberately no destroy(). Clinical rows point at a unit by id; deleting the row
 * would orphan them. Deactivation (setActive) is the only "this unit is done" action.
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

    /** UN-01 create. */
    public function store(UnitRequest $request): RedirectResponse
    {
        $unit = Unit::create($request->validated());

        AuditLog::record(
            'unit_create',
            'unit='.$unit->getKey().';code='.$unit->code,
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Unit '.$unit->code.' created.');
    }

    /** UN-01/02/03/05 update. */
    public function update(UnitRequest $request, Unit $unit): RedirectResponse
    {
        $data = $request->validated();

        // The delta is computed BEFORE the write and named by FIELD, never by value — a unit
        // name is not a secret, but the trail's job is "what changed", and a values-in-details
        // habit is how PHI eventually reaches an audit row (AccessControlController:197-199).
        $changed = array_keys(array_filter(
            $data,
            fn ($value, $key): bool => $unit->getAttribute($key) != $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        $unit->update($data);

        AuditLog::record(
            'unit_update',
            'unit='.$unit->getKey().';code='.$unit->code.';fields='.(implode(',', $changed) ?: 'none'),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Unit '.$unit->code.' updated.');
    }

    /**
     * UN-04: deactivation HIDES FORWARD and never deletes. Its own endpoint rather than a field
     * on update(), so retiring a unit is a deliberate single act with its own audit action —
     * and so it cannot ride along inside a rename the administrator thought was cosmetic.
     *
     * There is deliberately no destroy(). Clinical rows are never hard-deleted, and a unit that
     * owns handovers is the row those handovers point at.
     */
    public function setActive(Request $request, Unit $unit): RedirectResponse
    {
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];

        $unit->update(['active' => $active]);

        AuditLog::record(
            $active ? 'unit_activate' : 'unit_deactivate',
            'unit='.$unit->getKey().';code='.$unit->code,
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Unit '.$unit->code.($active ? ' is active.' : ' retired.'));
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
