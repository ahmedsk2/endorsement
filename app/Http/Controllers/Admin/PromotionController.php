<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Level;
use App\Support\Promotion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Promotion (cap:people.manage). Munawib LV-03, P1b Owner Decision A / P1c Decision D.
 *
 * There is no terminal level and none is inferred — a `terminal` column and a "what comes after
 * this level" inference method on `Level` were deliberately not built. The screen offers a
 * source level and a target level, both from
 * `offerableLevels()` — the ONE predicate this class offers from AND validates against, the
 * `SignoffPickers` discipline applied to a picker whose mistake would move a whole cohort
 * (the 2026-07-26 audit's finding, restated: `exists:levels,id` would accept `EXT` and any
 * retired level; `Rule::in(offerableLevels()->pluck('id'))` cannot).
 *
 * NOTHING HERE CREATES AN ACCOUNT — see `RosterNeverMintsCredentialsTest`.
 */
class PromotionController extends Controller
{
    /** The ONE predicate. `EXT` is external and appears in neither list (Decision D, point 2). */
    private static function offerableLevels(): Builder
    {
        return Level::query()->internal()->active()->ordered();
    }

    public function index(): Response
    {
        return Inertia::render('Admin/Promotion', [
            'levels' => self::offerableLevels()->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Read-only. No write, no transaction, no audit row — `Promotion::preview()` computes what a
     * commit WOULD do without doing it.
     */
    public function preview(Request $request): RedirectResponse
    {
        $data = $this->validatePicker($request);

        [$from, $to] = $this->resolveLevels($data);

        return back()->with('promotion_preview', Promotion::preview($from, $to, $data['effective_from']));
    }

    /**
     * `action=promote` writes through `Promotion::commit()` with the chosen target;
     * `action=retire` (Decision D's graduation path) commits with a NULL target — never a value
     * in the target picker that happens to mean "out" — deactivating the cohort and closing their
     * open spans instead. One summary audit row plus one row per person (Decision H); only the
     * summary is on `AuditAnomalies`' watch list.
     */
    public function commit(Request $request): RedirectResponse
    {
        $data = $this->validateCommit($request);

        [$from, $to] = $this->resolveLevels($data);

        $result = Promotion::commit($from, $to, $data['effective_from'], $data['ids'], [
            'actor' => $request->user()->getKey(),
            'reason' => $data['reason'] ?? null,
        ]);

        $actorId = $request->user()->getKey();
        $ip = $request->ip();
        $toId = $to?->getKey();

        // Ids and counts only — never a level CODE, which is administrator-authored free text
        // (CLAUDE.md's own rule, restated in Decision H).
        AuditLog::record(
            'person_promotion',
            'batch='.$result['batch'].';from_level='.$from->getKey()
                .';to_level='.($toId ?? 'none').';n='.count($result['outcomes']),
            $actorId,
            $ip,
        );

        foreach ($result['outcomes'] as $personId => $outcome) {
            AuditLog::record(
                'person_level_change',
                'person='.$personId.';level='.($toId ?? 'none').';batch='.$result['batch'],
                $actorId,
                $ip,
            );
        }

        return back()->with('promotion_result', $result);
    }

    /** @return array<string, mixed> */
    private function validatePicker(Request $request): array
    {
        $offered = self::offerableLevels()->pluck('id')->all();

        return $request->validate([
            'from_level_id' => ['required', 'integer', Rule::in($offered)],
            'to_level_id' => ['nullable', 'integer', Rule::in($offered)],
            // Not `date`: PHP's implicit date-string leniency once accepted a relative phrase and
            // created a real backdated clinical row elsewhere in this codebase — a lenient
            // sibling anywhere is the same bug waiting to happen.
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateCommit(Request $request): array
    {
        $offered = self::offerableLevels()->pluck('id')->all();

        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(['promote', 'retire'])],
            'from_level_id' => ['required', 'integer', Rule::in($offered)],
            'to_level_id' => ['required_if:action,promote', 'nullable', 'integer', Rule::in($offered)],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            // `person_levels.reason` is varchar(255) (migration 2026_08_14_120002) — this must
            // match the column, not guess wider than it. Review finding 3: MySQL 8.4 strict mode
            // throws an uncaught 1406 past 255 bytes; SQLite ignores the column length outright
            // and would let a 500-byte value through the test suite unnoticed.
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['action'] === 'retire') {
            $data['to_level_id'] = null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Level, 1: ?Level}
     */
    private function resolveLevels(array $data): array
    {
        $from = Level::findOrFail($data['from_level_id']);
        $to = $data['to_level_id'] === null ? null : Level::findOrFail($data['to_level_id']);

        if ($to !== null && $from->is($to)) {
            throw ValidationException::withMessages([
                'to_level_id' => 'Source and target are the same level — nothing to do.',
            ]);
        }

        return [$from, $to];
    }
}
