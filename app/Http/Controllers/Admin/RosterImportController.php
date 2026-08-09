<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RosterImportRequest;
use App\Models\AuditLog;
use App\Models\Level;
use App\Models\Position;
use App\Support\Roster\CsvRosterReader;
use App\Support\Roster\RosterFormatException;
use App\Support\Roster\RosterImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Roster import (cap:people.manage). Munawib ST-04, P1c Decision E.
 *
 * CSV/TSV only — Decision E is the owner's stated supply-chain reason there is no xlsx adapter
 * yet, not an oversight; the reader is a port (`App\Support\Roster\RosterReader`) so xlsx is one
 * class away the day the owner adds a spreadsheet dependency.
 *
 * THE DRY-RUN PREVIEW IS THE DELIVERABLE, NOT A NICETY (owner decision 3, 2026-08-09): a roster
 * import is never tested against a real staff list before it meets one, so the preview is the
 * only thing standing between a malformed file and a corrupted roster. `preview()` writes
 * NOTHING; `commit()` re-derives the SAME analysis inside its own transaction rather than
 * trusting anything the client sends back from an earlier preview call.
 *
 * NOTHING HERE CREATES AN ACCOUNT — see `RosterNeverMintsCredentialsTest`.
 */
class RosterImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/RosterImport', [
            'positions' => Position::orderBy('id')->get(['id', 'name']),
            'levels' => Level::query()->active()->ordered()->get(['id', 'code', 'name']),
        ]);
    }

    /** Read-only: no write, no transaction, no audit row — writes NOTHING (finding: preview is the deliverable). */
    public function preview(RosterImportRequest $request): RedirectResponse
    {
        $data = $request->validated();

        [$reader, $bytes] = $this->readerFromRequest($request);

        $analysis = RosterImport::preview($reader, $data['mapping']);

        return back()->with('roster_preview', $analysis + [
            // The Vue page's mapping <select> options — read from the SAME reader that produced
            // the analysis, never re-parsed client-side (this file's own docblock explains why).
            'headers' => $reader->headers(),
            'digest' => hash('sha256', $bytes),
        ]);
    }

    /**
     * The commit RE-READS the uploaded file and RE-DERIVES the report inside the transaction —
     * it never trusts what an earlier preview call said. `digest` ties the file being committed
     * to the exact bytes the operator previewed: a file that changed underneath (a different
     * export, a hand-edited row) is a 422 naming the mismatch, never a silent apply against
     * stale expectations.
     */
    public function commit(RosterImportRequest $request): RedirectResponse
    {
        $data = $request->validated();

        [$reader, $bytes] = $this->readerFromRequest($request);

        $actualDigest = hash('sha256', $bytes);
        $claimedDigest = (string) ($data['digest'] ?? '');

        if ($claimedDigest === '' || ! hash_equals($actualDigest, $claimedDigest)) {
            throw ValidationException::withMessages([
                'file' => 'This file has changed since you last previewed it — preview again before committing.',
            ]);
        }

        $result = RosterImport::commit($reader, $data['mapping'], $data['confirmations'] ?? [], $request);

        if ($result['file_errors'] !== []) {
            throw ValidationException::withMessages([
                'file' => 'This file has problems that must be fixed before it can be imported.',
            ]);
        }

        $actorId = $request->user()?->getKey();
        $ip = $request->ip();

        // Counts only — never a name, an address, or the filename (a filename is frequently a
        // person's name), matching CLAUDE.md's audit rule and Decision H's "ids and counts only".
        AuditLog::record(
            'roster_import',
            'created='.$result['summary']['created'].';updated='.$result['summary']['updated']
                .';skipped='.$result['summary']['skipped'],
            $actorId,
            $ip,
        );

        return back()->with('roster_result', $result);
    }

    /**
     * @return array{0: CsvRosterReader, 1: string} the reader, and the raw bytes read ONCE for
     *                                                the digest (never re-reading the temp upload
     *                                                a second time for that alone)
     */
    private function readerFromRequest(RosterImportRequest $request): array
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
