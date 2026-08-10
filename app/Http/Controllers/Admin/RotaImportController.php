<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RotaImportRequest;
use App\Models\Period;
use App\Support\Roster\CsvRosterReader;
use App\Support\Roster\RosterFormatException;
use App\Support\Rota\RotaImport;
use App\Support\Rota\StaleImportFileException;
use App\Support\Rota\StaleRotaStateException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Rota import (cap:rota.manage). Munawib MR-06, P1d-2 Decision H, Task 12.
 *
 * BEHIND `cap:rota.manage`, matching Task 10's export decision for the same reason turned the
 * other way round: a whole-year overwrite is at least as administrative as a whole-year
 * extraction, and this is the most destructive surface the rota has after the bulk fill.
 *
 * THIS CONTROLLER IS THINNER THAN `RosterImportController`, NOT A MIRROR OF IT, and Task 11's
 * amendment is why. `RosterImport` has no digest logic at all — its controller computes the
 * sha256 and throws the `ValidationException` itself — but transposing that shape here would have
 * put the importer's central safety property one task LATER than the code it protects. So the pin
 * lives inside `RotaImport`: `digest()` is one definition, `preview()` returns it, `commit()`
 * takes the operator's claim as an argument and refuses inside its own transaction. A pin a
 * controller can forget to apply is not a pin.
 *
 * What is left for a controller is exactly what a controller is for:
 *
 *  1. Turn the TYPED refusals into a 422 on the right field. `StaleImportFileException` (the file
 *     moved) and `StaleRotaStateException` (the rota moved) are caught by type rather than by
 *     matching an error message's text, which is the drift this codebase keeps removing — the
 *     message is the operator's, not a control-flow token. They land on DIFFERENT fields because
 *     they are different operator actions: one is fixed by re-exporting the file, the other by
 *     looking at the rota again, and the file is byte-identical in the second case.
 *  2. Flash the analysis so a screen can render it. Both keys are enumerated in
 *     `HandleInertiaRequests::share()`; a session key no `share()` names is invisible to every
 *     page in the app (Task 9's amendment records a whole feature that shipped that way, with all
 *     four suites green).
 *
 * IT WRITES NO AUDIT ROW. `RotaImport::commit()` appends `rota_import` itself, after its
 * transaction (finding 8), so a "helpful" row here would double every import in the trail.
 *
 * NOTHING HERE INVENTS A PERSON, A UNIT OR A PERIOD — see `RotaImport`'s docblock, difference 4,
 * and `RosterNeverMintsCredentialsTest`, which scans both files.
 */
class RotaImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/RotaImport', [
            // The years an operator could have exported, so the screen can point at the export
            // rather than describing it. Ids and labels only — this screen reads no rota.
            'academic_years' => Period::query()
                ->select('academic_year')->distinct()->orderBy('academic_year')->pluck('academic_year'),
            // Stated once, server-side, so the screen's own "up to N MB" sentence and the rule
            // that enforces it cannot drift apart.
            'max_kilobytes' => RotaImportRequest::MAX_KILOBYTES,
        ]);
    }

    /**
     * The dry run. Writes NOTHING — no transaction, no row, no audit — which is the property that
     * lets it be rendered as a preview at all, and it is `RotaImport::preview()`'s guarantee
     * rather than a promise this method makes.
     */
    public function preview(RotaImportRequest $request): RedirectResponse
    {
        $data = $request->validated();

        [$reader, $bytes] = $this->readerFromRequest($request);

        return back()->with('rota_import_preview', RotaImport::preview($reader, $data['kind'], $bytes));
    }

    /**
     * The commit RE-READS the uploaded file and RE-DERIVES the whole analysis inside
     * `RotaImport::commit()`'s own transaction; it trusts nothing an earlier preview said. The
     * operator's `digest` claims which bytes they looked at, and the refusal for a mismatch is the
     * importer's, not this method's.
     */
    public function commit(RotaImportRequest $request): RedirectResponse
    {
        $data = $request->validated();

        [$reader, $bytes] = $this->readerFromRequest($request);

        try {
            $result = RotaImport::commit(
                $reader,
                $data['kind'],
                $bytes,
                $data['digest'] ?? null,
                $request,
                $data['state_digest'] ?? null,
            );
        } catch (StaleImportFileException $e) {
            // The file changed under the operator. Their preview described a file that no longer
            // exists, so it is refused outright and never silently retried against the new bytes.
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        } catch (StaleRotaStateException $e) {
            // The ROTA changed under the operator — a different refusal on a different field,
            // because it is a different operator action with a different fix. Re-exporting the file
            // does nothing here; the file is byte-identical. See `StatePin`.
            throw ValidationException::withMessages(['state_digest' => $e->getMessage()]);
        }

        // A file-level problem refuses the WHOLE import — never "7 of 8 imported". `commit()` has
        // already declined to write anything and to audit anything; this is what stops the screen
        // rendering the refusal as a result.
        if ($result['file_errors'] !== []) {
            throw ValidationException::withMessages(['file' => $result['file_errors']]);
        }

        return back()->with('rota_import_result', $result);
    }

    /**
     * @return array{0: CsvRosterReader, 1: string} the reader, and the raw bytes read ONCE — the
     *                                              digest is over bytes, and re-reading the temp
     *                                              upload a second time for that alone would be a
     *                                              second chance to hash something else
     */
    private function readerFromRequest(RotaImportRequest $request): array
    {
        try {
            $path = $request->file('file')->getRealPath();
            $bytes = (string) file_get_contents($path);

            return [new CsvRosterReader($path), $bytes];
        } catch (RosterFormatException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
    }
}
