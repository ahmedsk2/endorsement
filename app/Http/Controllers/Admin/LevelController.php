<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LevelRequest;
use App\Models\AuditLog;
use App\Models\Level;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Structure → Levels (cap:structure.manage). Munawib LV-01: "names cosmetic and
 * editable". Create, rename, reorder, toggle `external`, and deactivate — never delete.
 *
 * There is deliberately no destroy(). `person_levels.level_id` is `restrictOnDelete`
 * (LevelHistoryTest pins the DB-level guard); a level that has ever been assigned to a person
 * is not something a form validation message can make deletable, so the controller does not
 * expose the action at all rather than let a constraint violation reach the user as a 500 —
 * the same shape UnitController's own docblock already established for units.
 *
 * OWNER DECISION A (2026-08-09, plan 2026-08-09-p1b-structure-admin.md): there is no
 * `terminal` flag and nothing here toggles one. See LevelLadderTest and this plan's Task 6/7/8
 * amendment notes.
 */
class LevelController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Levels', [
            'levels' => Level::query()->ordered()->get()->map(self::present(...))->values()->all(),
        ]);
    }

    /** LV-01 create. */
    public function store(LevelRequest $request): RedirectResponse
    {
        $level = Level::create($request->validated() + [
            // Provenance only (D11) — never a query filter. The P0d backfill deliberately
            // skipped `levels` (it had no rows at the time); every level created from here on
            // carries it from the acting administrator's own account.
            'institution_id' => $request->user()?->institution_id,
        ]);

        AuditLog::record(
            'level_create',
            'level='.$level->getKey().';code='.$level->code,
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Level '.$level->code.' created.');
    }

    /** LV-01 update (rename, reorder, toggle `external`). */
    public function update(LevelRequest $request, Level $level): RedirectResponse
    {
        $data = $request->validated();

        // The delta is computed BEFORE the write and named by FIELD, never by value — matches
        // UnitController::update()'s own precedent (AccessControlController:197-199).
        $changed = array_keys(array_filter(
            $data,
            fn ($value, $key): bool => $level->getAttribute($key) != $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        $level->update($data);

        AuditLog::record(
            'level_update',
            'level='.$level->getKey().';code='.$level->code.';fields='.(implode(',', $changed) ?: 'none'),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Level '.$level->code.' updated.');
    }

    /**
     * LV-01/LV-04: deactivation hides FORWARD and never deletes. Its own endpoint, mirroring
     * UnitController::setActive() — retiring a level is a deliberate single act with its own
     * audit action, not a field that can ride along inside a rename.
     */
    public function setActive(Request $request, Level $level): RedirectResponse
    {
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];

        $level->update(['active' => $active]);

        AuditLog::record(
            $active ? 'level_activate' : 'level_deactivate',
            'level='.$level->getKey().';code='.$level->code,
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Level '.$level->code.($active ? ' is active.' : ' retired.'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Level $level): array
    {
        return [
            'id' => (int) $level->getKey(),
            'code' => (string) $level->code,
            'name' => (string) $level->name,
            'display_order' => (int) $level->display_order,
            'active' => (bool) $level->active,
            'external' => (bool) $level->external,
        ];
    }
}
