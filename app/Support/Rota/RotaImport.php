<?php

namespace App\Support\Rota;

use App\Models\AuditLog;
use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\Vacation;
use App\Support\Calendar;
use App\Support\Roster\RosterReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Munawib MR-06's import (P1d-2 Decision H). It reads back the two files
 * `App\Support\Rota\RotaExport` writes, and it is built to `App\Support\Roster\RosterImport`'s
 * discipline — copied exactly where the shapes match, and each deliberate difference stated here
 * rather than discovered.
 *
 * SAME AS `RosterImport`.
 *  - `preview()` and `commit()` share ONE `analyse()`. That is what makes the preview honest: it
 *    is not a separate estimate of what a commit would do, it is the same function.
 *  - The WHOLE file is analysed before a single row is written.
 *  - The commit is PINNED to the sha256 of the exact bytes the preview parsed
 *    (`StaleImportFileException`), so a file re-saved, re-exported or hand-edited between the two
 *    is a refusal naming the fix, never a silent apply against expectations nobody saw.
 *  - A FILE-level error refuses the WHOLE import. Never "7 of 8 imported".
 *  - The reader is the ONE reader, behind the `RosterReader` port (finding 11): CSV/TSV today,
 *    xlsx one class plus one composer line away on the day the owner says so. There is no second
 *    reader here and `CsvIsTheOnlyReaderWriterTest` fails the build for one.
 *  - The audit row carries counts only and is appended AFTER the transaction commits (finding 8).
 *
 * AND ONE PIN `RosterImport` HAS NO NEED OF: **the rota itself** (`StaleRotaStateException`,
 * `stateDigest()`, {@see StatePin}). A roster import's analysis is a function of the file and the
 * people in it; a rota import's is a function of the file and the WHOLE CURRENT ROTA, which a
 * colleague can move while the preview is on screen. Re-deriving inside the transaction — which
 * this does, and must — is what makes that dangerous rather than safe: unchanged bytes then commit
 * whatever the FRESH analysis says. The slice review reproduced it twice, and the worse of the two
 * was a cell the preview showed with `spans: []` and the words "generate that academic year first"
 * committing as a `REPLACE` that collapsed a hand-made split. `RotaFill::digest()` is the sibling
 * pin on the same table in the same slice; both take their definition from `StatePin`.
 *
 * DIFFERENT, DELIBERATELY.
 *
 *  1. **No column-mapping.** `RosterImport` takes an operator `$mapping` because a hospital HR
 *     spreadsheet has arbitrary headers. A rota file round-trips from THIS system's own export, so
 *     the headers are fixed names — matched case-insensitively after trimming — and a missing
 *     required one is a file error naming it. `RotaExport`'s two header constants are the single
 *     definition; a mapping screen here would be ceremony wrapped around a drift surface.
 *
 *  2. **THE UNIT OF OUTCOME IS THE (person, period) CELL, NOT THE LINE** (finding 12). This is the
 *     largest shape difference from `RosterImport` and a reader coming from that file will assume
 *     otherwise. A roster row IS a person; a rota cell is a SET of spans, and
 *     `RotaAssignment::split()` replaces the whole set. So two lines describing two halves of one
 *     split period are ONE outcome — answering per line would promise the operator two independent
 *     writes that are in fact one. The contributing line numbers travel on the outcome so the row
 *     that caused an answer can still be found in the file.
 *
 *     **The cell is keyed on what a row RESOLVES TO**, not on how the file spelled it — see the
 *     comment in `analyseAssignments()`. A key built from raw text was wrong in both directions at
 *     once: two spellings of one period made two cells whose overlap was never compared (and
 *     `split()` replaces, so the second silently deleted the first), while folding case on the
 *     handle put two rows that resolve DIFFERENTLY under one key.
 *
 *  3. **It never rediscovers a retired person.** `RosterImport` matches `withTrashed()` on purpose;
 *     rediscovering a soft-deleted person instead of duplicating them is its whole job. Here the
 *     roster is not the importer's business: a handle resolving to a deactivated or soft-deleted
 *     person is `SKIP_UNKNOWN_PERSON` with *"no longer on the active roster"*. That is finding 10's
 *     offer/write parity — `RotaCellRequest` refuses to name such a person on a write, so this must
 *     too — and it is a live path rather than a hypothetical one, because Task 10 deliberately
 *     exports a stale person's spans (they are what block `PeriodController::destroy()`).
 *
 *  4. **IT NEVER INVENTS A PERSON, A UNIT OR A PERIOD.** Unknown handle → `SKIP_UNKNOWN_PERSON`.
 *     Unknown or retired `unit_code` → `SKIP_UNKNOWN_UNIT`. An `(academic_year, period_position)`
 *     pair with no row → `SKIP_UNKNOWN_PERIOD`. There is no create path for any of the three
 *     anywhere in this file, and `RosterNeverMintsCredentialsTest` scans it so one cannot grow for
 *     an account either — which also brings that guard's bare persistence needle with it, so this
 *     file contains no persistence call at all. It persists ONLY through `RotaAssignment` and
 *     `VacationBooking`, which is the strongest form of satisfying that needle.
 *
 *  5. **`SKIP_DUPLICATE`, on the leave file only.** Assignments are idempotent by construction —
 *     keyed on (person, period) and REPLACED — so importing the same file twice changes nothing. A
 *     vacation has NO natural key, so the same run twice would double every leave row. A row whose
 *     person and SNAPPED bounds already exist is a duplicate. The asymmetry is explainable rather
 *     than arbitrary, which is why it is named here.
 *
 * IT IS NOT A REPLACEMENT OF THE YEAR. It touches the cells its file names and no others: a
 * partial file is a partial update, never a silent clear of everything it omits.
 *
 * PERIOD RESOLUTION IS BY `(academic_year, position)` (finding 6, `periods_year_position_unique`),
 * never by `label` — labels are administrator-editable text and two years can share one.
 *
 * `week` GRANULARITY SNAPS THROUGH THE SAME CODE PATH AS THE SCREEN (owner decision 3,
 * 2026-08-10): `VacationBooking::snap()`, extracted from `book()` and called by `book()`, so the
 * claim is literally true rather than two similar-looking rules. The preview REPORTS the
 * adjustment — an operator must see that their week got wider before they commit it.
 */
final class RotaImport
{
    public const KIND_ASSIGNMENTS = 'assignments';

    public const KIND_VACATIONS = 'vacations';

    /** The cell holds nothing today and the file gives it spans. */
    public const CREATE = 'create';

    /** The cell holds something else today. `split()` replaces the whole set, never merges. */
    public const REPLACE = 'replace';

    /** The cell already holds exactly what the file describes — the round trip's normal answer. */
    public const UNCHANGED = 'unchanged';

    public const SKIP_UNKNOWN_PERSON = 'skip_unknown_person';

    public const SKIP_UNKNOWN_UNIT = 'skip_unknown_unit';

    public const SKIP_UNKNOWN_PERIOD = 'skip_unknown_period';

    /** Leave only — a vacation has no natural key. See the class docblock, difference 5. */
    public const SKIP_DUPLICATE = 'skip_duplicate';

    public const ERROR = 'error';

    /**
     * THE ROW CAP FOR THIS ARTEFACT, which is not the roster's (`CsvRosterReader::MAX_ROWS`, 2000).
     *
     * A rota file is ONE ROW PER SPAN. Sixty people across thirteen blocks is 780 rows before a
     * single split; a department that splits blocks routinely, or exports two academic years, is
     * past two thousand without anything unusual having happened — so the roster's number made this
     * system refuse to read a file it had itself just written, and did it as an uncaught 500
     * because the reader's cap fires mid-generator. THE SYSTEM MUST NOT BE ABLE TO EXPORT A FILE IT
     * REFUSES TO READ.
     *
     * Twenty thousand still catches what a cap is for — a pasted wrong file, a duplicated sheet —
     * with the 4 MB upload limit (`RotaImportRequest::MAX_KILOBYTES`) as the outer backstop at
     * roughly 50 000 rows of this shape. It is passed to the reader by `RotaImportController`, so
     * the refusal names the number that actually applied.
     */
    public const MAX_ROWS = 20000;

    /**
     * The columns an assignments file cannot be read without. The rest of
     * `RotaExport::ASSIGNMENT_HEADERS` (`period_label`, `period_starts_on`, `period_ends_on`,
     * `full_name`) is there for the human reading the file: this importer resolves the period from
     * `(academic_year, period_position)` and the person from `short_name`, so a hand-edited label
     * or a stale bound cannot change what gets written.
     *
     * @var list<string>
     */
    private const ASSIGNMENT_REQUIRED = [
        'academic_year', 'period_position', 'short_name', 'unit_code', 'starts_on', 'ends_on',
    ];

    /**
     * `source` is deliberately NOT required and never matched on: every row this importer writes
     * is `Vacation::SOURCE_IMPORT` (finding 7), and a manually-booked leave re-imported from an
     * export is a duplicate of itself regardless of what its source column says.
     *
     * @var list<string>
     */
    private const VACATION_REQUIRED = ['short_name', 'starts_on', 'ends_on', 'granularity'];

    /**
     * The dry run. Writes NOTHING — no transaction, no row, no audit — which is what lets a
     * controller render it as a preview.
     *
     * @return array<string, mixed>
     */
    public static function preview(RosterReader $reader, string $kind, string $bytes): array
    {
        $analysis = self::analyse($reader, $kind);

        // `context` carries the resolved Eloquent models `commit()` dispatches to the two writers
        // without a second round of queries. It never reaches a screen: a props payload built from
        // whole models is how a contact field reaches a page nobody meant to put it on (P1d-2
        // Decision C, finding 3) — the same reason `RotaFill::plan()` strips its own.
        unset($analysis['context']);

        $analysis['digest'] = self::digest($bytes);
        $analysis['state_digest'] = self::stateDigest($analysis);

        return $analysis;
    }

    /**
     * The commit RE-DERIVES the whole analysis inside its own transaction and never trusts what an
     * earlier preview said. Only cells resolving to `CREATE` or `REPLACE` are written; an
     * `UNCHANGED` cell is left entirely alone, which is what makes a re-import cost nothing rather
     * than rewriting rows to their own values.
     *
     * @param  string|null  $claimedDigest  the digest `preview()` returned for the bytes the
     *                                      operator actually looked at
     * @param  string|null  $claimedStateDigest  the digest `preview()` returned for the ROTA those
     *                                           bytes were analysed against
     * @return array<string, mixed> `preview()`'s shape plus `applied` — how many cells were written
     *
     * @throws StaleImportFileException when the file is not the one that was previewed
     * @throws StaleRotaStateException when the rota is not the one the preview was computed against
     */
    public static function commit(
        RosterReader $reader,
        string $kind,
        string $bytes,
        ?string $claimedDigest,
        Request $request,
        ?string $claimedStateDigest = null,
    ): array {
        $result = DB::transaction(function () use ($reader, $kind, $bytes, $claimedDigest, $claimedStateDigest): array {
            // THE PIN FIRST, before anything is read or written. Thrown rather than returned so it
            // cannot be walked past by a later edit: a `return` here is only safe while nothing
            // above it has written, and that is a property somebody has to keep true.
            $digest = self::digest($bytes);

            if ($claimedDigest === null || ! hash_equals($digest, $claimedDigest)) {
                throw new StaleImportFileException(
                    'This file has changed since you previewed it, so nothing has been imported. '
                    .'Preview it again to see what it would do now.'
                );
            }

            $analysis = self::analyse($reader, $kind);
            $context = $analysis['context'];
            unset($analysis['context']);

            $analysis['digest'] = $digest;
            $analysis['state_digest'] = self::stateDigest($analysis);
            $analysis['applied'] = 0;

            // THE SECOND PIN, and the one re-derivation alone cannot supply. Re-deriving means this
            // commit computes a FRESH answer from the CURRENT rota — so unchanged bytes can, and in
            // the reproductions that motivated this did, describe a completely different set of
            // writes from the one the operator approved: a cell previewed `UNCHANGED`, or skipped
            // with `spans: []` on screen, committing as a `REPLACE` that deleted both halves of a
            // split nobody was ever shown. The byte digest cannot see any of that, by construction.
            //
            // A DIFFERENT REFUSAL FROM THE BYTE ONE, and a different type, because they name
            // different operator actions: re-export the file, or re-preview it. See `StatePin`.
            if ($claimedStateDigest === null || ! hash_equals($analysis['state_digest'], $claimedStateDigest)) {
                throw new StaleRotaStateException(
                    'The rota changed since you previewed this file, so nothing has been imported. '
                    .'The file itself is unchanged — preview it again to see what it would do now.'
                );
            }

            // A file-level problem refuses the WHOLE import — never "7 of 8". Two lines disagreeing
            // about the file's own shape is a question about the file, and the operator answers it
            // there rather than by having this decide which rows to keep.
            if ($analysis['file_errors'] !== []) {
                return $analysis;
            }

            foreach ($analysis['outcomes'] as $outcome) {
                if ($outcome['outcome'] !== self::CREATE && $outcome['outcome'] !== self::REPLACE) {
                    continue;
                }

                $person = $context['people'][$outcome['person_id']] ?? null;

                if (! $person instanceof Person) {
                    continue;
                }

                if ($kind === self::KIND_ASSIGNMENTS) {
                    $period = $context['periods'][$outcome['period_id']] ?? null;

                    if (! $period instanceof Period) {
                        continue;
                    }

                    // THE ONE WRITER (Decision F). `split()` replaces the cell's whole span set,
                    // which is exactly the semantics a cell-shaped outcome promised; a merge is
                    // where an overlap sneaks past a check that only looked at what was submitted.
                    RotaAssignment::split($person, $period, array_map(
                        fn (array $span): array => [
                            'unit_id' => $span['unit_id'],
                            'starts_on' => $span['starts_on'],
                            'ends_on' => $span['ends_on'],
                        ],
                        $outcome['spans'],
                    ));
                } else {
                    // The ONE writer of leave, given the ALREADY-SNAPPED bounds and the file's own
                    // granularity — `book()` snaps again through the same `snap()` this analysis
                    // used, and snapping a snapped week is that same week (idempotent).
                    VacationBooking::book(
                        $person,
                        $outcome['snapped_starts_on'],
                        $outcome['snapped_ends_on'],
                        $outcome['granularity'],
                        Vacation::SOURCE_IMPORT,
                    );
                }

                $analysis['applied']++;
            }

            return $analysis;
        });

        // Finding 8: appended AFTER the transaction commits, never inside it. `AuditLog::record()`
        // opens its own transaction and locks the chain tail, so writing from inside would hold
        // that lock for the import's whole duration for no benefit — a failed import rolls back
        // regardless of when inside it a row was written. Ids, field names and counts only: no
        // filename (a filename is frequently a person's name), no handle, no unit code, no label.
        if ($result['file_errors'] === []) {
            AuditLog::record(
                'rota_import',
                'file='.$result['kind']
                    .';created='.$result['summary']['create']
                    .';replaced='.$result['summary']['replace']
                    .';unchanged='.$result['summary']['unchanged']
                    .';skipped='.$result['summary']['skipped']
                    .';errors='.$result['summary']['error'],
                $request->user()?->getKey(),
                $request->ip(),
            );
        }

        return $result;
    }

    /** ONE definition of the pin, used by both entry points — never re-typed at a call site. */
    public static function digest(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    /**
     * THE SECOND PIN: the ROTA the file was analysed against, not the file.
     *
     * `digest()` above answers "is this the file you looked at". It cannot answer "is this the rota
     * you looked at", and until the slice review that added this it was the ONLY pin an import had
     * — so a commit re-derived its analysis from a rota that had moved and wrote whatever the fresh
     * answer said, against an outcome set the operator never saw.
     *
     * The projection is per outcome: the cell's identity, what it holds NOW, and what would be
     * WRITTEN over it. `outcome` and `reason` are deliberately excluded — {@see StatePin} is the
     * one definition of that rule and states why, and `RotaFill::digest()` is its other consumer.
     *
     * A VACATION HAS NO PERIOD, so it pins `null` there and projects the snapped bounds as its
     * proposal. Its `current` is the already-booked leave that INTERSECTS that proposal — precisely
     * the set that decides the row's answer, and no wider: leave in another month cannot make this
     * row a different outcome, and refusing over it would make the pin unusable on a department
     * that books leave all year.
     *
     * @param  array<string, mixed>  $analysis
     */
    public static function stateDigest(array $analysis): string
    {
        return StatePin::of(
            'import:'.$analysis['kind'],
            // An import has no operator input beyond the file, and the file is pinned by its bytes.
            [],
            $analysis['file_errors'],
            array_map(static fn (array $outcome): array => [
                $outcome['person_id'],
                $outcome['period_id'] ?? null,
                $outcome['current'],
                $outcome['spans'] ?? [[
                    'starts_on' => $outcome['snapped_starts_on'],
                    'ends_on' => $outcome['snapped_ends_on'],
                ]],
            ], $analysis['outcomes']),
        );
    }

    /**
     * The ONE analysis `preview()` and `commit()` both call.
     *
     * @return array<string, mixed>
     */
    private static function analyse(RosterReader $reader, string $kind): array
    {
        return match ($kind) {
            self::KIND_ASSIGNMENTS => self::analyseAssignments($reader),
            self::KIND_VACATIONS => self::analyseVacations($reader),
            // A programming error, not operator input: the kind is chosen by a radio on the screen
            // and validated as an enum, never sniffed from the headers — a file whose shape is
            // guessed is a file that gets misread.
            default => throw new InvalidArgumentException(
                "Unknown rota import kind \"{$kind}\". Must be one of: "
                .self::KIND_ASSIGNMENTS.', '.self::KIND_VACATIONS.'.'
            ),
        };
    }

    // --- assignments -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function analyseAssignments(RosterReader $reader): array
    {
        [$map, $missing] = self::mapHeaders($reader, self::ASSIGNMENT_REQUIRED, RotaExport::ASSIGNMENT_HEADERS);

        if ($missing !== []) {
            return self::refused(self::KIND_ASSIGNMENTS, $missing);
        }

        $cells = [];
        $line = 1;   // line 1 is the header row the reader already consumed
        $people = [];
        $periods = [];
        $units = [];

        foreach ($reader->rows() as $raw) {
            $line++;
            $field = fn (string $name): string => trim((string) ($raw[$map[$name] ?? ''] ?? ''));

            $handle = $field('short_name');
            $year = $field('academic_year');
            $position = $field('period_position');
            $unitCode = $field('unit_code');
            $from = $field('starts_on');
            $to = $field('ends_on');

            if ($handle === '' && $year === '' && $position === '' && $unitCode === '' && $from === '' && $to === '') {
                // An entirely empty line contributes no cell. `SplFileObject::SKIP_EMPTY` catches
                // a truly blank line; this catches a row of separators, which a spreadsheet
                // produces when a range is selected one row too far.
                continue;
            }

            // IDENTITY IS RESOLVED PER ROW, BEFORE THE ROW IS GROUPED, because THE CELL KEY IS WHAT
            // THE ROW RESOLVES TO — not how the file spelled it. Built from raw text, the key was
            // wrong in both directions at once:
            //
            //  - Two spellings of one period (`1` and `+1`, both of which `filter_var()` reads as 1)
            //    made TWO cells resolving to the SAME (person, period). Each looked clean alone, so
            //    the whole-cell overlap check never compared them, both were applied, and
            //    `split()` REPLACES — the second silently deleted the first.
            //  - It FOLDED CASE on the handle while `resolvePerson()` matched the raw text, so one
            //    key could hold two rows that resolve DIFFERENTLY, and the cell took whichever
            //    spelling came first. On SQLite a mis-cased first row dragged the correctly-spelled
            //    row below it into the same skip; on MySQL, whose default collation compares
            //    `short_name` case-insensitively, both resolve to one person — which is precisely
            //    the merge the id-keyed form now performs on both engines.
            //
            // A row resolving to nothing falls back to the raw triple, folded, so two rows naming
            // the same non-existent handle are still one skip. `\x1f` (unit separator) joins the
            // parts because it cannot occur in a handle, a year or an id.
            [$person, $personProblem] = self::resolvePerson($handle, $people);
            $period = self::resolvePeriod($year, $position, $periods);

            $key = ($person !== null ? 'p'.$person->getKey() : 'h'.mb_strtolower($handle))
                ."\x1f".($period !== null ? 'b'.$period->getKey() : 'y'.mb_strtolower($year).':'.$position);

            if (! isset($cells[$key])) {
                $cells[$key] = [
                    'lines' => [],
                    'short_name' => $handle,
                    'full_name' => $field('full_name'),
                    'academic_year' => $year,
                    'period_position' => $position,
                    'period_label' => $field('period_label'),
                    'person' => $person,
                    'person_problem' => $personProblem,
                    'period' => $period,
                    'raw' => [],
                ];
            }

            $cells[$key]['lines'][] = $line;
            $cells[$key]['raw'][] = [
                'line' => $line, 'unit_code' => $unitCode, 'starts_on' => $from, 'ends_on' => $to,
            ];
        }

        $resolved = [];

        foreach ($cells as $cell) {
            $resolved[] = self::resolveAssignmentCell($cell, $units);
        }

        $current = self::currentSpans($resolved);

        $outcomes = [];
        $context = ['people' => [], 'periods' => []];

        foreach ($resolved as $cell) {
            $outcomes[] = self::finaliseAssignmentCell($cell, $current, $context);
        }

        return [
            'kind' => self::KIND_ASSIGNMENTS,
            'file_errors' => [],
            'outcomes' => $outcomes,
            'summary' => self::summarise($outcomes),
            'context' => $context,
        ];
    }

    /**
     * THE PERIOD, memoised across the file — one query per DISTINCT (year, position) pair rather
     * than one per line. Resolution is by `(academic_year, position)` (finding 6,
     * `periods_year_position_unique`), never by `label`: labels are administrator-editable text and
     * two years can share one.
     *
     * The MEMO key normalises the position the way the lookup does, so `1` and `+1` share one entry
     * — the same normalisation the cell key now depends on, written once here rather than twice.
     *
     * @param  array<string, Period|null>  $cache
     */
    private static function resolvePeriod(string $year, string $rawPosition, array &$cache): ?Period
    {
        $position = filter_var($rawPosition, FILTER_VALIDATE_INT);
        $key = mb_strtolower($year)."\x1f".($position === false ? '?'.$rawPosition : (string) $position);

        if (! array_key_exists($key, $cache)) {
            $cache[$key] = $position === false || $year === ''
                ? null
                : Period::query()->forYear($year)->where('position', $position)->first();
        }

        return $cache[$key];
    }

    /**
     * The SPANS, and the judgement about them. The person and the period arrived resolved, on the
     * cell, because the cell KEY is built from them (see `analyseAssignments()`); only the unit
     * lookup happens here, memoised across the file the same way.
     *
     * @param  array<string, mixed>  $cell
     * @param  array<string, Unit|null>  $units
     * @return array<string, mixed>
     */
    private static function resolveAssignmentCell(array $cell, array &$units): array
    {
        $cell['unit_problem'] = null;
        $cell['errors'] = [];
        $cell['spans'] = [];

        foreach ($cell['raw'] as $row) {
            $line = $row['line'];

            $from = self::parseYmd($row['starts_on'], $line, 'starts_on', $cell['errors']);
            $to = self::parseYmd($row['ends_on'], $line, 'ends_on', $cell['errors']);

            if ($from === null || $to === null) {
                continue;
            }

            if ($to < $from) {
                $cell['errors'][] = "Line {$line}: this span ends ({$to}) before it starts ({$from}).";

                continue;
            }

            if ($cell['period'] !== null) {
                $periodFrom = Calendar::ymd($cell['period']->starts_on);
                $periodTo = Calendar::ymd($cell['period']->ends_on);

                if ($from < $periodFrom || $to > $periodTo) {
                    $cell['errors'][] = "Line {$line}: this span ({$from}..{$to}) falls outside the period "
                        ."it names ({$periodFrom}..{$periodTo}).";

                    continue;
                }
            }

            $code = $row['unit_code'];

            if (! array_key_exists($code, $units)) {
                // `Unit::findByCode()` normalises the way the `code` mutator does on write — the
                // one lookup every code built from operator input goes through. It deliberately
                // carries no `active` scope, which is useful here rather than a nuisance: it is
                // what lets a retired unit and a non-existent one be told apart below.
                $units[$code] = $code === '' ? null : Unit::findByCode($code);
            }

            $unit = $units[$code];

            if ($unit === null) {
                $cell['unit_problem'] ??= $code === ''
                    ? 'This row names no unit.'
                    : "There is no such unit as \"{$code}\".";

                continue;
            }

            if (! $unit->active) {
                $cell['unit_problem'] ??= "\"{$code}\" has been retired, so nobody can be newly assigned to it.";

                continue;
            }

            $cell['spans'][] = [
                'line' => $line,
                'unit_id' => (int) $unit->getKey(),
                'unit_code' => (string) $unit->code,
                'starts_on' => $from,
                'ends_on' => $to,
            ];
        }

        usort($cell['spans'], fn (array $a, array $b): int => $a['starts_on'] <=> $b['starts_on']);

        // The overlap check runs over the WHOLE cell before any write, rather than being discovered
        // by `RotaAssignment::split()` part-way through a commit. One person on two units on one
        // day is a state the grid cannot render and the call roster cannot resolve.
        for ($i = 1; $i < count($cell['spans']); $i++) {
            if ($cell['spans'][$i]['starts_on'] <= $cell['spans'][$i - 1]['ends_on']) {
                $cell['errors'][] = 'Lines '.implode(', ', $cell['lines']).': two of these spans cover '
                    .'the same day. One person on two units on one day is a state the grid cannot render.';

                break;
            }
        }

        return $cell;
    }

    /**
     * THE PRECEDENCE, stated once. Identity first (person, then period), because without those two
     * there is no cell to say anything about and a preview blaming a span for a row whose person
     * does not exist reads as a different problem from the one the operator has. Then the file's
     * own errors, because they name something to fix in the file. Then the unit, which names
     * something about the department's configuration. Only then is there anything to compare.
     *
     * A cell holding one good span and one on a retired unit is `SKIP_UNKNOWN_UNIT` WHOLE — the
     * cell is the unit of outcome and `split()` replaces the whole set, so applying the half that
     * resolved would silently delete the half that did not.
     *
     * @param  array<string, mixed>  $cell
     * @param  array<string, list<array<string, mixed>>>  $current
     * @param  array<string, array<int, mixed>>  $context
     * @return array<string, mixed>
     */
    private static function finaliseAssignmentCell(array $cell, array $current, array &$context): array
    {
        $person = $cell['person'];
        $period = $cell['period'];

        $outcome = [
            'lines' => $cell['lines'],
            'short_name' => $cell['short_name'],
            'full_name' => $cell['full_name'],
            'academic_year' => $cell['academic_year'],
            'period_position' => $cell['period_position'],
            'period_label' => $cell['period_label'],
            'person_id' => $person?->getKey(),
            'period_id' => $period?->getKey(),
            'spans' => array_values(array_map(
                fn (array $span): array => [
                    'unit_id' => $span['unit_id'], 'unit_code' => $span['unit_code'],
                    'starts_on' => $span['starts_on'], 'ends_on' => $span['ends_on'],
                ],
                $cell['spans'],
            )),
            'current' => [],
            'errors' => [],
            'reason' => null,
            'outcome' => self::ERROR,
        ];

        if ($cell['person_problem'] !== null) {
            return ['outcome' => self::SKIP_UNKNOWN_PERSON, 'reason' => $cell['person_problem'], 'spans' => []] + $outcome;
        }

        if ($period === null) {
            return [
                'outcome' => self::SKIP_UNKNOWN_PERIOD,
                'reason' => "This department has no period {$cell['period_position']} in {$cell['academic_year']} "
                    .'— generate that academic year first, or correct the file.',
                'spans' => [],
            ] + $outcome;
        }

        if ($cell['errors'] !== []) {
            return ['outcome' => self::ERROR, 'errors' => $cell['errors'], 'spans' => []] + $outcome;
        }

        if ($cell['unit_problem'] !== null) {
            return ['outcome' => self::SKIP_UNKNOWN_UNIT, 'reason' => $cell['unit_problem'], 'spans' => []] + $outcome;
        }

        // A BACKSTOP, and named as one rather than dressed up as a behaviour: every line in a cell
        // either contributes a span, records an error, or sets `unit_problem`, so all three
        // branches above already fire before this can. It exists so that a future edit to the
        // resolution loop cannot silently reach `split()` with an empty set — which throws from
        // inside the import transaction and aborts a whole file over one cell.
        if ($outcome['spans'] === []) {
            return [
                'outcome' => self::ERROR,
                'errors' => ['Lines '.implode(', ', $cell['lines']).': this cell describes no span at all. '
                    .'To empty a cell, clear it on the rota — an import never clears what it does not name.'],
            ] + $outcome;
        }

        $held = $current[$person->getKey().'|'.$period->getKey()] ?? [];
        $outcome['current'] = $held;

        $comparable = fn (array $spans): array => array_map(
            fn (array $span): string => $span['unit_id'].'|'.$span['starts_on'].'|'.$span['ends_on'],
            $spans,
        );

        if ($comparable($held) === $comparable($outcome['spans'])) {
            $outcome['outcome'] = self::UNCHANGED;

            return $outcome;
        }

        $context['people'][$person->getKey()] = $person;
        $context['periods'][$period->getKey()] = $period;

        $outcome['outcome'] = $held === [] ? self::CREATE : self::REPLACE;

        return $outcome;
    }

    /**
     * What the named cells hold today, in ONE query rather than one per cell. Only cells whose
     * person and period both resolved are asked about — the rest are already skipped.
     *
     * @param  list<array<string, mixed>>  $resolved
     * @return array<string, list<array<string, mixed>>>
     */
    private static function currentSpans(array $resolved): array
    {
        $personIds = [];
        $periodIds = [];

        foreach ($resolved as $cell) {
            if ($cell['person_problem'] === null && $cell['person'] !== null && $cell['period'] !== null) {
                $personIds[(int) $cell['person']->getKey()] = true;
                $periodIds[(int) $cell['period']->getKey()] = true;
            }
        }

        if ($personIds === []) {
            return [];
        }

        $held = [];

        $rows = MasterRotaAssignment::query()
            ->whereIn('person_id', array_keys($personIds))
            ->whereIn('period_id', array_keys($periodIds))
            ->with('unit')
            ->orderBy('starts_on')
            ->get();

        foreach ($rows as $row) {
            $held[$row->person_id.'|'.$row->period_id][] = [
                'unit_id' => (int) $row->unit_id,
                // Read off the assignment's own relation, not an active-units map — a cell whose
                // current span sits on a RETIRED unit must still say so on screen, which is the
                // same reason `RotaExport` does not build itself from the grid (Task 5's amendment).
                'unit_code' => (string) $row->unit?->code,
                'starts_on' => Calendar::ymd($row->starts_on),
                'ends_on' => Calendar::ymd($row->ends_on),
            ];
        }

        return $held;
    }

    // --- vacations ---------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function analyseVacations(RosterReader $reader): array
    {
        [$map, $missing] = self::mapHeaders($reader, self::VACATION_REQUIRED, RotaExport::VACATION_HEADERS);

        if ($missing !== []) {
            return self::refused(self::KIND_VACATIONS, $missing);
        }

        $rows = [];
        $line = 1;

        foreach ($reader->rows() as $raw) {
            $line++;
            $field = fn (string $name): string => trim((string) ($raw[$map[$name] ?? ''] ?? ''));

            $handle = $field('short_name');
            $from = $field('starts_on');
            $to = $field('ends_on');
            $granularity = $field('granularity');

            if ($handle === '' && $from === '' && $to === '' && $granularity === '') {
                continue;
            }

            $rows[] = [
                'line' => $line, 'short_name' => $handle, 'full_name' => $field('full_name'),
                'starts_on' => $from, 'ends_on' => $to, 'granularity' => $granularity,
            ];
        }

        $people = [];
        $resolved = [];

        foreach ($rows as $row) {
            [$person, $problem] = self::resolvePerson($row['short_name'], $people);
            $row['person'] = $person;
            $row['person_problem'] = $problem;
            $resolved[] = $row;
        }

        // What each named person is already on leave for, in ONE query. The proposals this file
        // makes are appended to the same per-person list as they are accepted, so a duplicate or an
        // overlap WITHIN the file is caught by exactly the same comparison as one against the
        // database — never two rules that can disagree.
        $known = self::currentLeave($resolved);

        $outcomes = [];
        $context = ['people' => []];

        foreach ($resolved as $row) {
            $outcomes[] = self::finaliseVacationRow($row, $known, $context);
        }

        return [
            'kind' => self::KIND_VACATIONS,
            'file_errors' => [],
            'outcomes' => $outcomes,
            'summary' => self::summarise($outcomes),
            'context' => $context,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, list<array{starts_on: string, ends_on: string}>>  $known
     * @param  array<string, array<int, mixed>>  $context
     * @return array<string, mixed>
     */
    private static function finaliseVacationRow(array $row, array &$known, array &$context): array
    {
        $person = $row['person'];

        $outcome = [
            'lines' => [$row['line']],
            'short_name' => $row['short_name'],
            'full_name' => $row['full_name'],
            'person_id' => $person?->getKey(),
            'granularity' => $row['granularity'],
            'starts_on' => $row['starts_on'],
            'ends_on' => $row['ends_on'],
            'snapped_starts_on' => null,
            'snapped_ends_on' => null,
            'snapped' => false,
            // What this row's answer is DECIDED by: the leave this person already holds that
            // intersects the proposal. Named `current` to match an assignment outcome's own key,
            // because `stateDigest()` reads one projection over both files.
            'current' => [],
            'errors' => [],
            'reason' => null,
            'outcome' => self::ERROR,
        ];

        if ($row['person_problem'] !== null) {
            return ['outcome' => self::SKIP_UNKNOWN_PERSON, 'reason' => $row['person_problem']] + $outcome;
        }

        $errors = [];
        $line = $row['line'];

        $from = self::parseYmd($row['starts_on'], $line, 'starts_on', $errors);
        $to = self::parseYmd($row['ends_on'], $line, 'ends_on', $errors);

        if (! in_array($row['granularity'], [Vacation::GRANULARITY_DATE, Vacation::GRANULARITY_WEEK], true)) {
            $errors[] = "Line {$line}: \"{$row['granularity']}\" is not a leave granularity. Must be one of: "
                .Vacation::GRANULARITY_DATE.', '.Vacation::GRANULARITY_WEEK.'.';
        }

        if ($errors !== []) {
            return ['outcome' => self::ERROR, 'errors' => $errors] + $outcome;
        }

        if ($to < $from) {
            return ['outcome' => self::ERROR,
                'errors' => ["Line {$line}: this leave ends ({$to}) before it starts ({$from})."]] + $outcome;
        }

        // OWNER DECISION 3, the same code path as the booking screen — `book()` calls this too.
        $snapped = VacationBooking::snap($from, $to, $row['granularity']);

        $outcome['snapped_starts_on'] = $snapped['starts_on'];
        $outcome['snapped_ends_on'] = $snapped['ends_on'];
        $outcome['snapped'] = $snapped['starts_on'] !== $from || $snapped['ends_on'] !== $to;

        $personId = (int) $person->getKey();

        // GATHERED FIRST, THEN JUDGED, rather than judged as it goes. Behaviourally identical — a
        // duplicate IS an intersection, so the first intersecting entry decided the answer before
        // this too — but it leaves the deciding set on the outcome for `stateDigest()` to pin,
        // which is what stops a leave booked under the preview committing as an answer the operator
        // never saw. ONE overlap predicate, written once, feeding both the projection and the
        // judgement below.
        $intersecting = [];

        foreach ($known[$personId] ?? [] as $existing) {
            if ($existing['starts_on'] <= $snapped['ends_on'] && $existing['ends_on'] >= $snapped['starts_on']) {
                $intersecting[] = $existing;
            }
        }

        $outcome['current'] = $intersecting;

        foreach ($intersecting as $existing) {
            if ($existing['starts_on'] === $snapped['starts_on'] && $existing['ends_on'] === $snapped['ends_on']) {
                // The whole reason this outcome exists: leave has no natural key, so without this
                // the same file imported twice doubles every row in it.
                return ['outcome' => self::SKIP_DUPLICATE,
                    'reason' => 'This leave is already booked, to the day.'] + $outcome;
            }

            // `Vacation::booted()` would refuse this with a RuntimeException mid-commit, which
            // would abort the whole file over a row the preview called clean. Named here instead,
            // on that row alone.
            return ['outcome' => self::ERROR, 'errors' => [
                "Line {$line}: this leave ({$snapped['starts_on']}..{$snapped['ends_on']}) overlaps leave "
                ."already booked for the same person ({$existing['starts_on']}..{$existing['ends_on']}).",
            ]] + $outcome;
        }

        $known[$personId][] = ['starts_on' => $snapped['starts_on'], 'ends_on' => $snapped['ends_on']];
        $context['people'][$personId] = $person;

        $outcome['outcome'] = self::CREATE;

        return $outcome;
    }

    /**
     * @param  list<array<string, mixed>>  $resolved
     * @return array<int, list<array{starts_on: string, ends_on: string}>>
     */
    private static function currentLeave(array $resolved): array
    {
        $personIds = [];

        foreach ($resolved as $row) {
            if ($row['person_problem'] === null && $row['person'] !== null) {
                $personIds[(int) $row['person']->getKey()] = true;
            }
        }

        if ($personIds === []) {
            return [];
        }

        $known = [];

        foreach (Vacation::query()->whereIn('person_id', array_keys($personIds))->orderBy('starts_on')->get() as $row) {
            $known[(int) $row->person_id][] = [
                'starts_on' => Calendar::ymd($row->starts_on),
                'ends_on' => Calendar::ymd($row->ends_on),
            ];
        }

        return $known;
    }

    // --- shared ------------------------------------------------------------------------------

    /**
     * ONE matcher for both files: `short_name`, the app-wide UNIQUE human handle that is not a
     * contact field (finding 5). There is no second matching predicate anywhere in this class and
     * no fuzzy matching — `full_name` is carried through for the human reading the preview and is
     * never matched on, because two people can share a name and neither is the one the file meant.
     *
     * The lookup is `withTrashed()` on purpose, and NOT in order to rediscover anybody: it is the
     * only way to tell "there is no such handle" (correct the file) apart from "that person has
     * left" (correct the roster, or accept the gap). Both answers are the same outcome; a single
     * message would send the operator to the wrong screen half the time.
     *
     * @param  array<string, Person|null>  $cache
     * @return array{0: Person|null, 1: string|null} the person, and why they cannot be used
     */
    private static function resolvePerson(string $handle, array &$cache): array
    {
        if ($handle === '') {
            return [null, 'This row names no short name, so there is nobody to match it to. '
                .'The person it came from has no handle on the roster (Admin → People).'];
        }

        if (! array_key_exists($handle, $cache)) {
            $cache[$handle] = Person::withTrashed()->where('short_name', $handle)->first();
        }

        $person = $cache[$handle];

        if ($person === null) {
            return [null, "No person on this roster has the short name \"{$handle}\"."];
        }

        if ($person->trashed() || ! $person->active) {
            // DECISION H ITEM 3. Task 10 exports a stale person's spans deliberately — they are
            // what block `PeriodController::destroy()` — so this is a live path on any real round
            // trip, and it is finding 10's offer/write parity: `RotaCellRequest` refuses to name
            // such a person on a write, so this refuses to as well.
            return [$person, "\"{$handle}\" is no longer on the active roster, so nothing new can be "
                .'scheduled for them. Reactivate them first if this is wrong.'];
        }

        return [$person, null];
    }

    /**
     * Fixed header names, matched case-insensitively after trimming — there is no mapping screen
     * (difference 1). The KNOWN set comes from `RotaExport`'s own constants so the writing side and
     * the reading side cannot drift: a column renamed on one and not the other would otherwise
     * produce a file that imports as "missing required column".
     *
     * @param  list<string>  $required
     * @param  list<string>  $known
     * @return array{0: array<string, string>, 1: list<string>} canonical => actual header, and the missing required ones
     */
    private static function mapHeaders(RosterReader $reader, array $required, array $known): array
    {
        $map = [];

        foreach ($reader->headers() as $header) {
            $canonical = mb_strtolower(trim($header));

            if (in_array($canonical, $known, true) && ! isset($map[$canonical])) {
                $map[$canonical] = $header;
            }
        }

        return [$map, array_values(array_diff($required, array_keys($map)))];
    }

    /**
     * @param  list<string>  $missing
     * @return array<string, mixed>
     */
    private static function refused(string $kind, array $missing): array
    {
        return [
            'kind' => $kind,
            'file_errors' => [
                'This file is missing the column(s) '.implode(', ', $missing).'. Import the file '
                .'Admin → Master Rota\'s "'.$kind.'" export produced, with its header row intact.',
            ],
            'outcomes' => [],
            'summary' => ['create' => 0, 'replace' => 0, 'unchanged' => 0, 'skipped' => 0, 'error' => 0],
            'context' => ['people' => [], 'periods' => []],
        ];
    }

    /**
     * `Calendar::parse()` accepts Y-m-d and nothing else, on purpose — leniency is what let
     * "+5 years" create backdated rows once. Every date in both files goes through it.
     *
     * @param  list<string>  $errors
     */
    private static function parseYmd(string $value, int $line, string $column, array &$errors): ?string
    {
        try {
            return Calendar::ymd(Calendar::parse($value));
        } catch (InvalidArgumentException) {
            $errors[] = "Line {$line}: \"{$value}\" is not a date this system can read in the {$column} "
                .'column — use YYYY-MM-DD.';

            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     * @return array<string, int>
     */
    private static function summarise(array $outcomes): array
    {
        $summary = ['create' => 0, 'replace' => 0, 'unchanged' => 0, 'skipped' => 0, 'error' => 0];

        foreach ($outcomes as $outcome) {
            $summary[match ($outcome['outcome']) {
                self::CREATE => 'create',
                self::REPLACE => 'replace',
                self::UNCHANGED => 'unchanged',
                self::ERROR => 'error',
                default => 'skipped',
            }]++;
        }

        return $summary;
    }
}
