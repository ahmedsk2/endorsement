<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Support\Engine\EvaluationRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * `php artisan engine:evaluate` — P2's demoable artifact, and the first real caller of both halves.
 *
 * ```
 * php artisan engine:evaluate 2026-2027-01 storage/app/block-01.json
 * ```
 *
 * It takes a PERIOD and a duty document, builds this department's real evaluation context from the
 * shipped master rota, leave, clinics, levels, periods and calendar, pipes CG-10's three arguments
 * through the compiled Node entrypoint, and prints what came back: the violations grouped by
 * severity with the CG-04 plain-language explanation the engine generated for each, and then
 * `coverage()`'s skipped windows — what could NOT be checked, which on a first draft is the more
 * surprising half of the answer.
 *
 * ## THE DOCUMENT
 *
 * Everything the shipped schema does not record, and nothing it does. SL-01..SL-07 are P3, no table
 * anywhere holds a duty anybody has ever worked, the conditions gate is P3's, and owner decision R
 * keeps unwanted days out of P2 entirely — so those halves arrive from a file or not at all.
 *
 * ```jsonc
 * {
 *   "slots":      [ { "key": "night", "kind": "call", "cadence": "daily", "spanDays": 1,
 *                     "startMinute": 1200, "endMinute": 480, "crossesMidnight": true,
 *                     "countsHours": true, "tallyKey": "nights" } ],
 *   "duties":     [ { "personKey": "p12", "date": "2026-08-06", "slotKey": "night" } ],
 *   "priorDuties":     [ ],       // already published, BEFORE the period. Read, never re-judged.
 *   "followingDuties": [ ],       // and after it. Usually empty, never assumed so.
 *   "historyAvailableFrom": "2026-07-01",   // how far back the two lists above are complete
 *   "unwantedDays": { "p12": ["2026-08-14"] },
 *   "conditions": [ { "id": "c-density", "typeKey": "call_frequency_max", "class": "soft",
 *                     "rank": 1, "active": true, "params": { "n": 3, "windowDays": 28 } } ]
 * }
 * ```
 *
 * `personKey` is `p` + `people.id` (owner decision G, `ContextBuilder::personKey()`); slot, unit and
 * level keys are the department's own codes. A duty naming a person the context does not describe
 * makes the engine throw, deliberately — a Hard rule answering *"no violation"* for want of data is
 * strictly worse than a crash.
 *
 * ## THREE THINGS THIS COMMAND IS NOT
 *
 * **Not a production path.** The server runtime is P3's `services/engine` (owner decision Y), whose
 * first real caller is CG-05's publish gate. This command refuses outright when the application is
 * in `production`, so a convenience never becomes an undocumented dependency on `node` living in
 * the app container — which it does not, and must not have to.
 *
 * **Not a writer, and it audits nothing.** It reads tables and a file and prints. There is no
 * clinical event and no access event here to record; and a violation's explanation is generated
 * from the schedule, so nothing it produces may go anywhere near `audit_log.detail`.
 *
 * **Not fed by real data in tests.** Its fixtures are synthetic and permanently so. The owner may
 * of course point it at this department's real rota — that is the demo — but nothing real enters
 * this repository.
 *
 * ## EXIT CODES — THE ENTRYPOINT'S OWN, MIRRORED
 *
 * | Code | Meaning |
 * |---|---|
 * | 0 | The request was evaluated. **Violations found or not — that is data, not failure.** |
 * | 2 | This is not something to evaluate: bad period, unreadable document, contract failure, production. |
 * | 3 | The engine refused: a condition names a type key it cannot resolve. |
 * | 1 | A bug, or `node` could not be started at all. |
 *
 * There is deliberately no *"violations were found"* code, for the reason
 * `packages/engine/bin/evaluate.mjs` records: a status meaning both *"I could not do this"* and
 * *"I did it and found things"* is one every caller special-cases and one caller forgets. The
 * consequence that matters here is the opposite one — **a missing bundle, an unstartable `node` or
 * a malformed payload must never read as a clean schedule.** Every non-zero exit from the child is
 * surfaced verbatim and returned; nothing below prints a verdict on a run that did not happen.
 *
 * ## THE COMPILED BUNDLE
 *
 * `packages/engine/dist/engine.mjs` is gitignored and built by `npm run build:engine`; CI rebuilds
 * it before every run. When it is absent the entrypoint says so in one sentence naming that command
 * and exits 2, and this command prints that sentence rather than inventing a second copy of the
 * check — one definition of *"is the engine built"*, on the side that knows.
 *
 * ## WHY THE REPORT READS `Condition.class` AND NOT THE VIOLATION'S `severity`
 *
 * They cannot differ: `evaluate()` stamps severity from the condition row and P2 Decision E's
 * *"the engine never overrides the row"* is structural rather than a rule twenty-two types
 * remember. Reading the row instead buys two things — the report can name the type key and the rank
 * beside each group, which the violation deliberately does not carry, and this file stays inside
 * `RulesLiveOnlyInTheEngineTest`'s reach for that needle. That guard exempts this file for
 * `violation` alone, because CG-10's array is literally named `violations` and there is no honest
 * spelling that avoids it; every other needle, this one included, still scans here — and this is
 * exactly the file where a *"quick PHP pre-check so we do not have to spawn node"* would be born.
 *
 * ## ORDER
 *
 * The order inside each group is `evaluate()`'s own — by condition, then by location — and this
 * command does not re-sort. CG-02's precedence order is `comparePrecedence()`'s, in the package,
 * and a second definition of it here would be one that drifts. The rank is PRINTED beside each
 * condition so a reader can see it; it is not applied.
 */
class EngineEvaluate extends Command
{
    protected $signature = 'engine:evaluate
        {period : The period key, e.g. 2026-2027-01 (run with an unknown one to list them)}
        {document : Path to the JSON document carrying the slots, duties and conditions}
        {--all : Print every finding and every skipped window rather than the first few of each}';

    protected $description = 'Evaluate a drafted period against the conditions engine and print what it found (local demo; never a production path)';

    /** Mirrors `packages/engine/bin/evaluate.mjs`. There is no "violations were found" code. */
    private const EXIT_OK = 0;

    private const EXIT_BUG = 1;

    private const EXIT_BAD_REQUEST = 2;

    /** How many rows of one list are printed before the rest are counted, unless `--all`. */
    private const PRINT_LIMIT = 20;

    public function handle(): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->error('engine:evaluate is a local demo and refuses to run in production.');
            $this->line('  The server runtime is services/engine (P3, owner decision Y). This command');
            $this->line('  would make `node` in the app container an undocumented dependency.');

            return self::EXIT_BAD_REQUEST;
        }

        $period = $this->resolvePeriod((string) $this->argument('period'));

        if ($period === null) {
            return self::EXIT_BAD_REQUEST;
        }

        $document = $this->readDocument((string) $this->argument('document'));

        if ($document === null) {
            return self::EXIT_BAD_REQUEST;
        }

        try {
            $request = EvaluationRequest::forPeriod($period, $document);
        } catch (Throwable $error) {
            $this->error('This document cannot be assembled into a CG-10 request:');
            $this->line('  '.$error->getMessage());

            return self::EXIT_BAD_REQUEST;
        }

        $payload = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            $this->error('The assembled request could not be encoded as JSON: '.json_last_error_msg());

            return self::EXIT_BUG;
        }

        $this->summarise($period, $request, strlen($payload));

        $startedAt = hrtime(true);

        try {
            $result = Process::timeout(300)
                ->input($payload)
                ->run(['node', base_path('packages/engine/bin/evaluate.mjs')]);
        } catch (Throwable $error) {
            $this->error('`node` could not be started, so nothing was evaluated - this is NOT a clean schedule.');
            $this->line('  '.$error->getMessage());

            return self::EXIT_BUG;
        }

        $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $code = $result->exitCode() ?? self::EXIT_BUG;

        if ($code !== self::EXIT_OK) {
            $this->newLine();
            $this->error("The engine did not evaluate this request (exit {$code}). Nothing below is a verdict.");

            foreach (preg_split('/\r\n|\r|\n/', trim($result->errorOutput())) ?: [] as $line) {
                $this->line('  '.$line);
            }

            return $code;
        }

        $answer = json_decode($result->output(), true);

        if (! is_array($answer) || ! is_array($answer['violations'] ?? null) || ! is_array($answer['coverage'] ?? null)) {
            $this->error('The engine exited 0 but did not return the envelope it promises.');

            return self::EXIT_BUG;
        }

        $this->report($answer['violations'], $answer['coverage'], $request['conditions']);
        $this->cost($elapsedMs);

        return self::EXIT_OK;
    }

    /**
     * The period, by the key `ContextBuilder` gives it inside the payload.
     *
     * An unknown key lists what this instance actually has, because the alternative is an operator
     * guessing at the spelling of a key nothing on any screen shows them.
     */
    private function resolvePeriod(string $key): ?Period
    {
        $periods = Period::query()->orderBy('academic_year')->orderBy('position')->get();

        foreach ($periods as $period) {
            if (EvaluationRequest::periodKey($period) === $key) {
                return $period;
            }
        }

        $this->error("No period is keyed \"{$key}\".");

        if ($periods->isEmpty()) {
            $this->line(' This instance has no periods at all - Admin > Structure > Periods builds the year.');

            return null;
        }

        $this->line('  This instance has:');

        foreach ($periods as $period) {
            $this->line(sprintf(
                '  %s | %s..%s | %s',
                EvaluationRequest::periodKey($period),
                $period->starts_on->format('Y-m-d'),
                $period->ends_on->format('Y-m-d'),
                (string) $period->label,
            ));
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function readDocument(string $path): ?array
    {
        $absolute = $this->absolutePath($path);

        if (! is_file($absolute) || ! is_readable($absolute)) {
            $this->error("No readable document at {$absolute}.");
            $this->line('  It carries the halves this schema records none of: slots, duties, the');
            $this->line('  carry-in tail, unwanted days and the condition rows. See this command\'s docblock.');

            return null;
        }

        $decoded = self::withEmptyObjectsKept(json_decode((string) file_get_contents($absolute)));

        if (! is_array($decoded) || array_is_list($decoded)) {
            $this->error("{$absolute} is not a JSON object: ".json_last_error_msg());

            return null;
        }

        return $decoded;
    }

    /**
     * Decode a JSON tree into arrays, keeping an EMPTY object an object.
     *
     * `json_decode($raw, true)` turns `{}` and `[]` into the same PHP value, and `json_encode` then
     * turns both back into `[]`. That is not cosmetic here: `Condition.params` is typed as an
     * object by the contract's own schema and half the catalog takes no parameters at all, so
     * `"params": {}` — the correct spelling for `vacation_block`, `overlap_block` and
     * `unwanted_day_block` — arrives at the entrypoint as an array and is refused, on the very rows
     * a department is most likely to switch on first. Measured, not predicted: the first real run of
     * this command against a populated department was refused for exactly that, on two rows.
     *
     * So objects are decoded as objects and converted to arrays only when they have something in
     * them — a non-empty map re-encodes as an object anyway, and an empty one keeps the only
     * evidence PHP has that it was ever one. STATED RESIDUAL: an object whose keys are all decimal
     * integers ("0", "1", …) becomes a list. No key in the CG-10 contract has that shape — person
     * keys carry the `p` prefix owner decision G asked for — and the alternative is a per-key
     * allow-list that would silently miss the next object-valued field somebody adds.
     */
    private static function withEmptyObjectsKept(mixed $node): mixed
    {
        if ($node instanceof \stdClass) {
            $fields = get_object_vars($node);

            return $fields === [] ? $node : array_map([self::class, 'withEmptyObjectsKept'], $fields);
        }

        if (is_array($node)) {
            return array_map([self::class, 'withEmptyObjectsKept'], $node);
        }

        return $node;
    }

    private function absolutePath(string $path): string
    {
        $looksAbsolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        return $looksAbsolute ? $path : base_path($path);
    }

    /**
     * What is about to be evaluated, printed BEFORE the child runs.
     *
     * The evaluable range is on its own line and says where it came from, because it is the number
     * this whole command was written around: availability is a fact about that range, and a horizon
     * claiming a range the context does not describe is P2 Task 21's silent false positive.
     *
     * @param  array<string, mixed>  $request
     */
    private function summarise(Period $period, array $request, int $payloadBytes): void
    {
        $horizon = $request['schedule']['horizon'];
        $context = $request['context'];
        $active = count(array_filter($request['conditions'], fn (array $row): bool => ($row['active'] ?? false) === true));

        $this->line('<options=bold>engine:evaluate</> - local demo of packages/engine. Not a production path.');
        $this->newLine();
        $this->line(sprintf(
            'period %s | %s..%s | %s',
            EvaluationRequest::periodKey($period),
            $horizon['from'],
            $horizon['to'],
            (string) $period->label,
        ));
        $this->line(sprintf(
            'evaluable %s..%s | %d days | the period plus every date a supplied duty occupies, which is'
            .' also the range the day vector and availability were built over',
            $horizon['evaluableFrom'],
            $horizon['evaluableTo'],
            count($context['days']),
        ));
        $this->line(sprintf(
            'context people %d | slots %d | clinics %d | tail history from %s',
            count($context['people']),
            count($context['slots']),
            count($context['clinics']),
            $context['historyAvailableFrom'] ?? 'nowhere (none supplied)',
        ));
        $this->line(sprintf(
            'schedule duties in the period %d | before it %d | after it %d',
            count($request['schedule']['duties']),
            count($context['priorDuties']),
            count($context['followingDuties']),
        ));
        $this->line(sprintf(
            'conditions rows %d | active %d | request %d KB',
            count($request['conditions']),
            $active,
            (int) ceil($payloadBytes / 1024),
        ));
    }

    /**
     * The answer, grouped by the class each condition was AUTHORED with.
     *
     * @param  list<mixed>  $findings
     * @param  list<mixed>  $coverage
     * @param  list<array<string, mixed>>  $conditions
     */
    private function report(array $findings, array $coverage, array $conditions): void
    {
        $rows = array_column($conditions, null, 'id');
        $groups = ['hard' => [], 'soft' => [], '' => []];

        foreach ($findings as $finding) {
            $id = is_array($finding) ? (string) ($finding['conditionId'] ?? '') : '';
            $class = (string) ($rows[$id]['class'] ?? '');
            $groups[array_key_exists($class, $groups) ? $class : ''][$id][] = $finding;
        }

        if ($findings === []) {
            $this->newLine();
            $this->line('<info>Nothing was flagged.</info> Every active condition ran and found no breach, which is'
                .' a different statement from "every rule was checked" - read the coverage below.');
        }

        $this->printGroup('HARD - CG-05, these block a publish', $groups['hard'], $rows);
        $this->printGroup('SOFT - CG-06, ranked advice', $groups['soft'], $rows);
        $this->printGroup('REPORTED AGAINST NO CONDITION ROW THIS COMMAND SUPPLIED', $groups[''], $rows);

        $this->printCoverage($coverage, $rows);
    }

    /**
     * @param  array<string, list<mixed>>  $byCondition
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function printGroup(string $heading, array $byCondition, array $rows): void
    {
        if ($byCondition === []) {
            return;
        }

        $this->newLine();
        $this->line('<options=bold>'.$heading.'</>');

        foreach ($byCondition as $id => $findings) {
            $row = $rows[$id] ?? [];
            $rank = array_key_exists('rank', $row) ? ', rank '.(string) $row['rank'] : '';

            $this->newLine();
            $this->line(sprintf(
                '[%s] %s%s - %d found',
                (string) $id,
                (string) ($row['typeKey'] ?? 'unknown type'),
                $rank,
                count($findings),
            ));

            $this->printCapped($findings, function (mixed $finding): void {
                $this->line(sprintf(
                    '- %s - %s',
                    $this->whereOf($finding['location'] ?? null),
                    (string) ($finding['explanation'] ?? ''),
                ));
            });
        }
    }

    /**
     * WHERE a finding is, in the three shapes `Location` has. A window names the duties that
     * contributed, because a range on its own is not something a scheduler can act on (WB-03).
     */
    private function whereOf(mixed $location): string
    {
        if (! is_array($location)) {
            return 'somewhere this report cannot describe';
        }

        return match ($location['kind'] ?? null) {
            'placement' => sprintf(
                '%s | %s | %s',
                (string) $location['personKey'],
                (string) $location['date'],
                (string) $location['slotKey'],
            ),
            'window' => sprintf(
                '%s | %s..%s | %d contributing',
                (string) $location['personKey'],
                (string) $location['from'],
                (string) $location['to'],
                count($location['contributing'] ?? []),
            ),
            'cohort' => sprintf(
                '%s | %d people',
                (string) $location['scopeLabel'],
                count($location['personKeys'] ?? []),
            ),
            default => 'somewhere this report cannot describe',
        };
    }

    /**
     * `coverage()`'s half — printed even when nothing was flagged, and especially then.
     *
     * A condition that is switched off, a floor that could only see part of a window and a rule
     * whose carry-in history does not reach are all reported here with nothing evaluated. On a
     * green run that is the whole of the interesting news.
     *
     * @param  list<mixed>  $coverage
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function printCoverage(array $coverage, array $rows): void
    {
        $this->newLine();
        $this->line('<options=bold>COVERAGE - what was measured, and what could not be</>');

        if ($coverage === []) {
            $this->line('Nothing reported. With no condition rows there is nothing to measure.');

            return;
        }

        foreach ($coverage as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = (string) ($entry['conditionId'] ?? '');
            $skipped = is_array($entry['skipped'] ?? null) ? $entry['skipped'] : [];

            $this->newLine();
            $this->line(sprintf(
                '[%s] %s - %d measured, %d left unjudged',
                $id,
                (string) ($rows[$id]['typeKey'] ?? 'unknown type'),
                (int) ($entry['evaluatedWindows'] ?? 0),
                count($skipped),
            ));

            $this->printCapped($skipped, function (mixed $window): void {
                $this->line(sprintf(
                    '- %s..%s - %s',
                    (string) ($window['from'] ?? '?'),
                    (string) ($window['to'] ?? '?'),
                    (string) ($window['reason'] ?? ''),
                ));
            });
        }
    }

    /**
     * Print a list, or the first {@see PRINT_LIMIT} of it and a count of the rest.
     *
     * A four-week rolling window over a month enumerates fifty-odd windows and skips most of them
     * at a first draft, and a violation-dense schedule generates findings in the hundreds — a demo
     * that scrolls the answer off the top of the terminal has not shown anybody anything. The
     * remainder is COUNTED rather than dropped, and `--all` lifts the cap.
     *
     * @param  list<mixed>  $items
     */
    private function printCapped(array $items, callable $print): void
    {
        $limit = $this->option('all') ? count($items) : self::PRINT_LIMIT;

        foreach (array_slice($items, 0, $limit) as $item) {
            $print($item);
        }

        if (count($items) > $limit) {
            $this->line(sprintf('- plus %d more (--all prints them)', count($items) - $limit));
        }
    }

    /**
     * NF-01, made visible rather than assumed.
     *
     * The budget is 100 ms for `evaluate()` alone and P2 Task 23 measured it MISSED on a
     * deliberately violation-dense case (~120 ms median on one developer machine). What this line
     * prints is bigger than that and deliberately says so: it is the whole round trip, including
     * Node start-up, JSON on both wires, and BOTH traversals — `coverage()` is a second full pass,
     * which is the first place to look if the budget ever matters at request scale.
     */
    private function cost(int $elapsedMs): void
    {
        $this->newLine();
        $this->line(sprintf(
            'engine round trip %d ms | node start-up + JSON both ways + evaluate() + coverage()',
            $elapsedMs,
        ));
        $this->line('NF-01 budgets 100 ms for evaluate() ALONE and P2 measured it MISSED (~120 ms on a'
            .' violation-dense month); coverage() is a second full traversal. See docs/INVARIANTS.md.');
    }
}
