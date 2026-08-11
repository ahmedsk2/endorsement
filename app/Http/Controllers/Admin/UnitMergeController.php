<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Support\UnitMerge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Structure → Units → Merge (cap:structure.manage). Munawib UN-01.
 *
 * `index()` renders the picker AND, once both a source and a target are chosen (query params
 * `source`/`target`), the LIVE plan — the same `UnitMerge::plan()` the commit path recomputes,
 * so what the operator confirms is exactly what will happen. `store()` commits, but only after
 * every colliding date has been individually confirmed — never at insert time.
 */
class UnitMergeController extends Controller
{
    public function index(Request $request): Response
    {
        $units = Unit::query()->ordered()->get();

        $sourceId = $request->integer('source') ?: null;
        $targetId = $request->integer('target') ?: null;

        $plan = null;
        $fieldDefinitionConflicts = [];
        $clinicsTheTargetCannotOwn = 0;

        if ($sourceId !== null && $targetId !== null && $sourceId !== $targetId) {
            $source = $units->firstWhere('id', $sourceId);
            $target = $units->firstWhere('id', $targetId);

            if ($source !== null && $target !== null) {
                $plan = UnitMerge::plan($source, $target);
                $fieldDefinitionConflicts = UnitMerge::conflictingFieldDefinitionKeys($source, $target);
                $clinicsTheTargetCannotOwn = UnitMerge::clinicsTheTargetCannotOwn($source, $target);
            }
        }

        return Inertia::render('Admin/UnitMerge', [
            'units' => $units->map(fn (Unit $unit): array => [
                'id' => (int) $unit->getKey(),
                'code' => (string) $unit->code,
                'name' => (string) $unit->name,
                'active' => (bool) $unit->active,
            ])->values()->all(),
            'source_unit_id' => $sourceId,
            'target_unit_id' => $targetId,
            'plan' => $plan,
            'field_definition_conflicts' => $fieldDefinitionConflicts,
            'clinics_the_target_cannot_own' => $clinicsTheTargetCannotOwn,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source_unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'target_unit_id' => ['required', 'integer', 'different:source_unit_id', Rule::exists('units', 'id')],
            'resolution' => ['nullable', 'string', Rule::in([UnitMerge::KEEP_TARGET, UnitMerge::ABORT])],
            'accepted_collisions' => ['array'],
            'accepted_collisions.*' => ['date_format:Y-m-d'],
        ], [
            'target_unit_id.different' => 'A unit cannot be merged into itself.',
        ]);

        $source = Unit::findOrFail($data['source_unit_id']);
        $target = Unit::findOrFail($data['target_unit_id']);
        $resolution = $data['resolution'] ?? null;

        // `abort` is a named, explicit refusal — checked before anything else touches the
        // database, so it is always honoured regardless of what else is true about the pair.
        if ($resolution === UnitMerge::ABORT) {
            return back()->with('status', "Merge of {$source->code} into {$target->code} cancelled. No changes were made.");
        }

        if (! $target->active) {
            throw ValidationException::withMessages([
                'target_unit_id' => 'The target unit must be active.',
            ]);
        }

        $plan = UnitMerge::plan($source, $target);

        if ($plan['collisions'] !== []) {
            if ($resolution === null) {
                throw ValidationException::withMessages([
                    'resolution' => 'Both units already have a signed day on '
                        .count($plan['collisions']).' of the same date(s). Choose how to resolve '
                        .'every colliding date before merging.',
                ]);
            }

            $accepted = $data['accepted_collisions'] ?? [];
            sort($accepted);
            $expected = $plan['collisions'];
            sort($expected);

            if ($accepted !== $expected) {
                throw ValidationException::withMessages([
                    'accepted_collisions' => 'Acknowledge every colliding date before merging.',
                ]);
            }
        } else {
            $accepted = [];
        }

        $conflicts = UnitMerge::conflictingFieldDefinitionKeys($source, $target);

        if ($conflicts !== []) {
            throw ValidationException::withMessages([
                'target_unit_id' => 'Both units define a custom field with the same key ('
                    .implode(', ', $conflicts).'). Rename or retire one before merging.',
            ]);
        }

        // `commit()` refuses this too, inside its transaction. It is asked here as well so the
        // operator reads a sentence naming the tick-box rather than a 500 — the same two-layer
        // shape the custom-field conflict above already has.
        $homelessClinics = UnitMerge::clinicsTheTargetCannotOwn($source, $target);

        if ($homelessClinics > 0) {
            throw ValidationException::withMessages([
                'target_unit_id' => $homelessClinics.' clinic(s) on '.$source->code.' have nowhere '
                    .'to go: '.$target->code.' does not own clinics. Tick "owns clinics" on it in '
                    .'Admin → Structure → Units first.',
            ]);
        }

        UnitMerge::commit($source, $target, $accepted, $request->user()->getKey(), $request->ip());

        return redirect()->route('admin.structure.units')
            ->with('status', "Unit {$source->code} merged into {$target->code}. {$source->code} is now retired.");
    }
}
