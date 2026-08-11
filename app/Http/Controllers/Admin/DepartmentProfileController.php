<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DepartmentProfileRequest;
use App\Models\AuditLog;
use App\Models\Institution;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Structure → Department (cap:structure.manage). Munawib ST-01's first step —
 * "profile", read as the department's display NAME and nothing else.
 *
 * Design §14 item 12 recorded `institutions.name` and `institutions.code` as env-only:
 * INSTITUTION_NAME / INSTITUTION_CODE, read by `ReferenceSeeder`, so changing either meant a
 * direct database edit outside the audit trail. This screen closes the `name` half.
 *
 * THE CODE STAYS ENV-ONLY, and it is not a UI omission. `institutions.code` is
 * `ReferenceSeeder`'s `firstOrNew` key: re-coding a live institution makes the next
 * `db:seed --force` — a mandatory step of every deploy — create a SECOND institution row
 * rather than update the first, at which point `Institution::current()` returns null (D11: two
 * rows means no right answer) and every screen that reads the department's configuration goes
 * blank. It is also provenance under D11, stamped on rows that already exist. Re-coding is a
 * provisioning operation with a migration behind it, not a settings change, so the field is
 * rendered read-only WITH ITS REASON and the write path below never accepts it — the
 * FormRequest validates `name` alone, which is where mass assignment is actually decided.
 *
 * `institutions.code` is NOT `App\Support\Instance::slug()`. Different value, different
 * variable (INSTANCE_SLUG), different job — the slug names the backup archive and the host
 * scripts' config/log/state files, and never appears on this screen.
 *
 * DELIBERATELY NOT FLUSHING THE CALENDAR MEMO. This controller is on
 * `CalendarWritersFlushTest::ALLOW_LIST` because it reads the institution row, following the
 * precedent that file already carries for `ContactVisibility` ("a PE-02 policy column, not a
 * calendar one"). `name` is a display column: `Calendar::settings()` memoises the six calendar
 * values as an array and holds no name, so there is nothing stale here for a flush to clear,
 * and calling one would imply a relationship that does not exist.
 *
 * BRANDING, in ST-01's sense, stops at the name. A logo upload into a system holding
 * children's PHI is its own security decision — storage path, content-type validation, path
 * traversal, and the size it adds to every backup archive — and is not smuggled in here.
 */
class DepartmentProfileController extends Controller
{
    /**
     * Rendered beside the read-only code. Stated once, here, so the screen and this class
     * cannot drift into two different explanations of the same refusal.
     */
    private const CODE_NOTE = 'Set at provisioning from INSTITUTION_CODE, and not editable '
        .'here. It is the key `php artisan db:seed --force` matches this institution on, so '
        .'changing it on a live deployment creates a second institution row instead of '
        .'renaming this one — a provisioning operation, not a settings change. It identifies '
        .'this customer inside the database only: it does not name backup archives or any '
        .'file on the host.';

    public function index(): Response
    {
        $institution = Institution::current();

        return Inertia::render('Admin/DepartmentProfile', [
            'name' => (string) ($institution?->name ?? ''),
            'code' => (string) ($institution?->code ?? ''),
            'code_note' => self::CODE_NOTE,
            // D11: zero or many active rows means there is no single department to configure.
            // The screen says so rather than rendering an input that would save nothing.
            'configurable' => $institution !== null,
        ]);
    }

    public function update(DepartmentProfileRequest $request): RedirectResponse
    {
        $institution = Institution::current();

        if ($institution === null) {
            // D11 again: guessing would rename a department that is not this deployment's.
            // The same refusal CalendarSettingsController::update() already makes.
            return back()->with('error', 'This deployment has no single active institution to '
                .'configure. Run `php artisan db:seed --force` first.');
        }

        $data = $request->validated();

        // The delta is computed BEFORE the write and named by FIELD, never by value — the
        // UnitController / LevelController / CalendarSettingsController precedent. A
        // department's name is a department-identifying string and is covered by the same
        // audit rule as PHI (docs/COMPLIANCE.md), so neither the old nor the new one is
        // written to the trail.
        $changed = array_keys(array_filter(
            $data,
            fn ($value, $key): bool => $institution->getAttribute($key) != $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        $institution->fill($data)->save();

        AuditLog::record(
            'institution_profile_update',
            'fields='.(implode(',', $changed) ?: 'none'),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Department profile saved.');
    }
}
