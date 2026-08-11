<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Demo\DemoDepartment;
use App\Support\Demo\DemoLedger;
use App\Support\Demo\DemoReferences;
use App\Support\Demo\DemoRemovalBlockedException;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Admin → Somewhere to practise (Munawib ST-05, P1e Decision F and Task 14) —
 * `/admin/structure/demo`, `cap:structure.manage`, one GET and two writes.
 *
 * ---------------------------------------------------------------------------------------------
 * THIS MAY BE PRESSED ON THE LIVE INSTANCE, AND THAT IS WHAT THE SCREEN IS SHAPED AROUND
 * ---------------------------------------------------------------------------------------------
 * Owner ruling, 2026-08-11. ST-05's *"for training"* means the instance people are trained on,
 * which is the live one. `DemoSeeder` and `E2eSeeder` throw in production and are right to — they
 * mark nothing they write and have no removal path, so a row either creates is indistinguishable
 * from a real one forever. `DemoDepartment` is ledgered, provably removable
 * (`DemoRoundTripTest` compares every table in the schema around a create-and-remove) and mints no
 * account at all, so pressing this on a system holding children's records creates no way in.
 *
 * The screen's job is to make all of that legible BEFORE either button: what a demo department is,
 * that every row it creates is visibly labelled, what would be created here specifically, what
 * would be removed, and what — if anything — is currently holding the removal.
 *
 * ---------------------------------------------------------------------------------------------
 * THE GET IS THE PREVIEW. THERE IS NO PREVIEW POST, DELIBERATELY
 * ---------------------------------------------------------------------------------------------
 * Every other preview-then-confirm surface in this codebase previews with a POST because the
 * preview takes operator input: a file to parse, a cohort to select, a source cell to fill from.
 * This one takes none — neither action has a single field beyond the confirmation itself — so the
 * state each is pinned to is computed when the page is rendered and posted back with the confirm.
 * A `POST /preview` here would be a button whose only job is to show what the page already shows,
 * and it would sit one boolean away from the destructive path. Both pins travel as props instead,
 * which means neither confirm control can be reached without the request that computed one.
 *
 * ---------------------------------------------------------------------------------------------
 * BOTH WRITES ARE PINNED, AND EACH PIN CATCHES SOMETHING ITS OPERATION CANNOT
 * ---------------------------------------------------------------------------------------------
 * `App\Support\Rota\StatePin` is the one definition of what a preview-then-confirm commit is
 * pinned to (invariant 12); `DemoDepartment::planDigest()` and `::removalDigest()` are the two
 * consumers and neither re-types the rule. Re-deriving inside the operation is necessary and NOT
 * sufficient, exactly as that class documents: re-deriving means computing a FRESH answer, so
 * without a pin a creation previewed as "this will generate its own academic year" quietly places
 * itself in the department's real one instead, and a removal confirmed against a batch somebody
 * else replaced removes a department this operator never saw. Both are silent, and both leave
 * every count and every guard green.
 *
 * A STALE PIN IS A RE-READ, NOT A DEAD END. The refusal says the page moved and lands the operator
 * back on a freshly-computed one; nothing is queued, retried or remembered.
 *
 * ---------------------------------------------------------------------------------------------
 * THE REFUSAL SHOWS WHAT IS HOLDING IT
 * ---------------------------------------------------------------------------------------------
 * `DemoDepartment::remove()` refuses WHOLE the moment a real row leans on a demo one — a genuine
 * handover written on the demo unit during training, an account claimed against a demo person, a
 * sign-off naming one, which is medico-legal evidence. A screen that only said "refused" would be
 * a dead end, so the `(table, count)` list is rendered from the pre-flight on every page load, not
 * only after a failed attempt, and the operator's remedy is named. Tables and counts only: no
 * name, no address, no patient anything (invariant 9).
 *
 * ---------------------------------------------------------------------------------------------
 * NOTHING HERE IS AUDITED, AND THAT IS NOT AN OMISSION
 * ---------------------------------------------------------------------------------------------
 * `DemoDepartment` writes exactly one summary row per outcome — `demo_department_create`,
 * `demo_department_remove`, `demo_department_remove_refused` — from inside the operation, so the
 * console command and this screen produce the same trail rather than two. A refusal this
 * controller raises before calling the writer (a stale pin, a mistyped word) is an unsubmitted
 * form, not an attempt on the data, and audits nothing for the same reason `preflight()` does not.
 *
 * NO CALENDAR FLUSH AND NO ALLOW-LIST ENTRY: this class reads the projection and never the
 * department's configuration row, so it matches none of `CalendarWritersFlushTest`'s needles.
 * `DemoDepartment` itself is on that list, with its reason at the site.
 */
class DemoDepartmentController extends Controller
{
    /**
     * The word an operator types to confirm a removal — the `PeriodController::destroy()` idiom
     * (`confirm_academic_year`), and for its reason: this is a hard delete with no undo anywhere in
     * the product, because neither rota table nor `clinics` soft-deletes and the hash-chained
     * `audit_log` is the history. The demo unit's own code is what is typed, because it is the one
     * short string the screen can show and the operator can verify names THIS department.
     */
    private const CONFIRM_WORD = DemoDepartment::UNIT_CODE;

    public function index(): Response
    {
        $plan = DemoDepartment::plan();

        return Inertia::render('Admin/DemoDepartment', [
            // What creating one would do here, and why it might not be offered.
            'plan' => [
                'refusals' => $plan['refusals'],
                'skipped' => $plan['skipped'],
                'pin' => DemoDepartment::planDigest($plan),
            ],
            // The existing one, if there is one: what it consists of, what is holding its removal,
            // and the pin its confirmation carries back. `facts` and the raw ledger rows stay on
            // the server — the screen has no use for either and a props payload is a disclosure.
            'demo' => $this->currentDemo(),
            'confirm_word' => self::CONFIRM_WORD,
            'unit_code' => DemoDepartment::UNIT_CODE,
            'label_prefix' => DemoDepartment::LABEL_PREFIX,
            'address_domain' => DemoDepartment::EMAIL_DOMAIN,
        ]);
    }

    /**
     * ST-05's one click. No typed word: creation is the reversible half — that is the whole point
     * of the ledger — and asking for a ceremony here would teach the operator to type past the one
     * that guards the irreversible half.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['pin' => ['required', 'string']]);

        $plan = DemoDepartment::plan();

        if ($plan['refusals'] !== []) {
            // Under the same key as a stale pin, because both mean the same thing to the operator:
            // this page is describing a department that is no longer the one in front of them.
            throw ValidationException::withMessages(['create_pin' => $plan['refusals'][0]]);
        }

        $this->assertPinned($data['pin'], DemoDepartment::planDigest($plan), 'create_pin');

        try {
            $result = DemoDepartment::create(
                $request->user()?->institution_id,
                $request->user()?->getKey(),
                $request->ip(),
            );
        } catch (InvalidArgumentException $e) {
            // Reachable only by a genuine race — the refusals above were clear a moment ago. The
            // writer refuses before writing, so nothing is half-built.
            throw ValidationException::withMessages(['create_pin' => $e->getMessage()]);
        }

        return back()
            ->with('status', "Created the demo department — {$result['rows']} rows.")
            // The skips are the point of flashing a structured result rather than a sentence: a
            // demo that quietly differs from the one the last person saw is how an operator
            // concludes the button is broken.
            ->with('demo_result', [
                'rows' => $result['rows'],
                'skipped' => $result['skipped'],
            ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pin' => ['required', 'string'],
            'confirm_demo' => ['required', 'string'],
        ]);

        if ($data['confirm_demo'] !== self::CONFIRM_WORD) {
            throw ValidationException::withMessages([
                'confirm_demo' => 'Type '.self::CONFIRM_WORD.' exactly to confirm. Nothing has been removed.',
            ]);
        }

        $batches = DemoLedger::batches();

        if ($batches === []) {
            throw ValidationException::withMessages([
                'confirm_demo' => 'No demo department exists. Nothing was removed.',
            ]);
        }

        $state = DemoDepartment::removalState((string) end($batches));

        $this->assertPinned($data['pin'], DemoDepartment::removalDigest($state), 'remove_pin');

        try {
            $result = DemoDepartment::remove(
                $state['batch'],
                $request->user()?->getKey(),
                $request->ip(),
            );
        } catch (DemoRemovalBlockedException $e) {
            // Reachable by a race the pin cannot see: a real row landing between the digest above
            // and the transaction. The writer refused WHOLE and audited it; the screen the operator
            // returns to re-reads the pre-flight, so what is holding it is on the page as data and
            // not only in this sentence.
            throw ValidationException::withMessages(['confirm_demo' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['confirm_demo' => $e->getMessage()]);
        }

        return back()->with('status', "Removed the demo department — {$result['rows']} rows.");
    }

    /**
     * @return array{batch: string, total: int, counts: list<array{table: string, count: int}>, blocked: list<array{table: string, count: int}>, swept: list<array{table: string, count: int}>, pin: string}|null
     */
    private function currentDemo(): ?array
    {
        $batches = DemoLedger::batches();

        if ($batches === []) {
            return null;
        }

        $state = DemoDepartment::removalState((string) end($batches));

        return [
            // The batch id is a UUID this class minted; it identifies nothing outside the ledger
            // and is what an operator quotes when asking why a removal was refused.
            'batch' => $state['batch'],
            'total' => count($state['rows']),
            'counts' => $state['counts'],
            // Each blocker carries the remedy for ITS table, from the same `DemoReferences::REMEDIES`
            // the refusal message is built from — one definition, two consumers. The generic
            // "delete them or point them somewhere real" was wrong for half of these: accounts are
            // deactivated and never deleted, sign-offs are never deleted at all.
            'blocked' => array_map(
                static fn (array $row): array => $row + [
                    'remedy' => DemoReferences::REMEDIES[$row['table']] ?? null,
                ],
                $state['blocked'],
            ),
            // Rows removal will hard-delete that the demo never created — an invitation addressed
            // to a demo person. Rendered before the word is typed, never after the fact.
            'swept' => $state['swept'],
            'pin' => DemoDepartment::removalDigest($state),
        ];
    }

    private function assertPinned(string $submitted, string $current, string $key): void
    {
        if (hash_equals($current, $submitted)) {
            return;
        }

        throw ValidationException::withMessages([
            $key => 'This page was describing a different department — something changed since it '
                .'was opened. Nothing has been done. Reload and read it again before confirming.',
        ]);
    }
}
