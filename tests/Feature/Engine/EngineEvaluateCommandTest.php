<?php

namespace Tests\Feature\Engine;

use App\Models\Institution;
use App\Models\Period;
use App\Models\Person;
use App\Support\Calendar;
use App\Support\Engine\ContextBuilder;
use App\Support\Engine\EvaluationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * P2 Task 24 — `php artisan engine:evaluate`, the phase's demoable artifact.
 *
 * ## WHAT IS ASSERTED HERE AND WHAT IS ASSERTED WITHOUT NODE
 *
 * Two of the cases below spawn the real `node` against the compiled bundle, which is gitignored and
 * built by `npm run build:engine`. They SKIP when it is absent — `CompiledCssIsLightOnlyTest`'s
 * shape, for a build artifact this suite does not produce — and a skip that could quietly become
 * permanent is a test nobody runs, so {@see test_ci_builds_the_bundle_before_the_php_suite} closes
 * that: it fails the build if the workflow ever stops building the engine before `php artisan
 * test`. Everything else here fakes the child process and runs unconditionally, including the two
 * refusals that matter most — a missing bundle and an unresolvable type key must never read as a
 * clean schedule.
 *
 * ## THE CASE THAT WOULD FIRE UNDER THE WRONG READING
 *
 * {@see test_availability_for_the_period_alone_fires_on_a_month_that_breaches_nothing} is P2 Task
 * 21 finding 6, run through the real engine on one world with one field changed. The schedule, the
 * conditions, the horizon and the day vector are identical in both halves; only `eligibleDays` is
 * narrowed to the period, exactly as a caller building the context over the period and widening the
 * horizon afterwards would produce. The correct half reports nothing; the narrowed half reports a
 * breach on the same clean month. That pair is the whole argument for
 * `EvaluationRequest::forPeriod()` reading the evaluable bounds off the day vector.
 */
class EngineEvaluateCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-08-02';

    private const TO = '2026-08-15';

    /**
     * The earliest already-published duty, 27 days before the period.
     *
     * That makes the evaluable range 41 days, so a 28-day rolling window fits inside it in fourteen
     * positions. The leftmost of those — 2026-07-06 → 2026-08-02 — is the one the defect lives in:
     * it holds ONE date of the period, so a caller supplying availability for the period alone
     * reports the person as available on a single day of a four-week window, permits
     * `floor(1 / 3) = 0` calls, and flags the one duty sitting in it.
     */
    private const TAIL_DUTY_DATE = '2026-07-06';

    private string $documentPath;

    protected function setUp(): void
    {
        parent::setUp();

        Institution::query()->create([
            'code' => 'QCH',
            'name' => 'Test Institution',
            'active' => true,
            'weekend_days' => [5, 6],
        ]);

        Calendar::flush();

        $this->documentPath = (string) tempnam(sys_get_temp_dir(), 'engine-doc-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->documentPath)) {
            unlink($this->documentPath);
        }

        parent::tearDown();
    }

    /**
     * Owner decision Y: the server runtime is P3's `services/engine`. A convenience command that
     * ran in production would make `node` in the app container a dependency nothing documents.
     */
    public function test_it_refuses_to_run_in_production(): void
    {
        $period = $this->seedWorld();
        $this->writeDocument($this->cleanDocument());

        $this->app['env'] = 'production';

        $this->artisan('engine:evaluate', ['period' => EvaluationRequest::periodKey($period), 'document' => $this->documentPath])
            ->expectsOutputToContain('refuses to run in production')
            ->assertExitCode(2);
    }

    /** An operator cannot see a period key on any screen, so an unknown one has to say what exists. */
    public function test_an_unknown_period_key_lists_the_ones_this_instance_has(): void
    {
        $this->seedWorld();
        $this->writeDocument($this->cleanDocument());

        $this->artisan('engine:evaluate', ['period' => '1999-2000-07', 'document' => $this->documentPath])
            ->expectsOutputToContain('No period is keyed "1999-2000-07"')
            ->expectsOutputToContain('2026-2027-01')
            ->assertExitCode(2);
    }

    public function test_an_unreadable_document_is_refused_before_anything_is_spawned(): void
    {
        $period = $this->seedWorld();
        Process::fake();

        $this->artisan('engine:evaluate', [
            'period' => EvaluationRequest::periodKey($period),
            'document' => 'storage/app/no-such-document.json',
        ])->assertExitCode(2);

        Process::assertNothingRan();
    }

    /**
     * THE ONE THAT MATTERS MOST. `dist/` is gitignored and built by a script; when it is absent the
     * entrypoint exits 2 with a sentence naming the command that builds it. The command must print
     * that sentence and return that code — never a verdict.
     *
     * Faked rather than performed, because the two states this suite can be in (bundle present, and
     * bundle absent) cannot both hold in one run, and the property under test is the PASS-THROUGH:
     * any non-zero child exit is surfaced verbatim and returned.
     */
    public function test_a_missing_bundle_is_never_read_as_a_clean_schedule(): void
    {
        $period = $this->seedWorld();
        $this->writeDocument($this->cleanDocument());

        Process::fake(['*' => Process::result(
            output: '',
            errorOutput: "The compiled engine is missing at C:\\x\\packages\\engine\\dist\\engine.mjs.\nBuild it first: npm run build:engine\n",
            exitCode: 2,
        )]);

        $this->artisan('engine:evaluate', ['period' => EvaluationRequest::periodKey($period), 'document' => $this->documentPath])
            ->expectsOutputToContain('did not evaluate this request (exit 2)')
            ->expectsOutputToContain('npm run build:engine')
            ->doesntExpectOutputToContain('Nothing was flagged')
            ->assertExitCode(2);
    }

    /**
     * Exit 3 is a different repair by a different person — a condition naming a type key the engine
     * cannot resolve — and the command keeps the two apart rather than collapsing both to 1.
     */
    public function test_an_unresolvable_type_key_returns_the_engines_own_code(): void
    {
        $period = $this->seedWorld();
        $this->writeDocument($this->cleanDocument());

        Process::fake(['*' => Process::result(
            output: '',
            errorOutput: 'No condition type "call_freqency_max" is registered.',
            exitCode: 3,
        )]);

        $this->artisan('engine:evaluate', ['period' => EvaluationRequest::periodKey($period), 'document' => $this->documentPath])
            ->expectsOutputToContain('(exit 3)')
            ->expectsOutputToContain('call_freqency_max')
            ->doesntExpectOutputToContain('Nothing was flagged')
            ->assertExitCode(3);
    }

    /** A zero exit whose stdout is not the promised envelope is a bug, not an empty answer. */
    public function test_a_zero_exit_with_the_wrong_envelope_is_a_bug_and_not_an_empty_answer(): void
    {
        $period = $this->seedWorld();
        $this->writeDocument($this->cleanDocument());

        Process::fake(['*' => Process::result(output: '[]', exitCode: 0)]);

        $this->artisan('engine:evaluate', ['period' => EvaluationRequest::periodKey($period), 'document' => $this->documentPath])
            ->expectsOutputToContain('did not return the envelope it promises')
            ->doesntExpectOutputToContain('Nothing was flagged')
            ->assertExitCode(1);
    }

    /**
     * Not a writer, and it audits nothing. There is no clinical or access event here to record, and
     * a violation's explanation is generated from the schedule — so it must never approach
     * `audit_log.detail`.
     */
    public function test_it_writes_nothing_and_audits_nothing(): void
    {
        $period = $this->seedWorld();
        $this->writeDocument($this->cleanDocument());

        $before = $this->rowCounts();

        Process::fake(['*' => Process::result(
            output: json_encode(['violations' => [], 'coverage' => []]),
            exitCode: 0,
        )]);

        $this->artisan('engine:evaluate', ['period' => EvaluationRequest::periodKey($period), 'document' => $this->documentPath])
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('audit_log')->count(), 'the demo command wrote an audit row');
        $this->assertSame($before, $this->rowCounts(), 'the demo command wrote a row');
    }

    /**
     * The report itself: grouped by the class the condition row was AUTHORED with, each finding
     * carrying the engine's own CG-04 sentence, and the coverage half printed underneath.
     */
    public function test_the_report_groups_by_class_and_prints_the_engines_own_sentences(): void
    {
        $period = $this->seedWorld();
        $person = Person::query()->first();
        $this->writeDocument($this->cleanDocument());

        Process::fake(['*' => Process::result(output: (string) json_encode([
            'violations' => [[
                'conditionId' => 'c-density',
                'severity' => 'soft',
                'rank' => 1,
                'location' => [
                    'kind' => 'window',
                    'personKey' => ContextBuilder::personKey($person),
                    'from' => self::TAIL_DUTY_DATE,
                    'to' => self::FROM,
                    'contributing' => [],
                ],
                'explanation' => 'A sentence the engine generated.',
            ]],
            'coverage' => [[
                'conditionId' => 'c-density',
                'evaluatedWindows' => 14,
                'skipped' => [['from' => '2026-07-05', 'to' => '2026-08-01', 'reason' => 'A reason the engine generated.']],
            ]],
        ]), exitCode: 0)]);

        $this->artisan('engine:evaluate', ['period' => EvaluationRequest::periodKey($period), 'document' => $this->documentPath])
            ->expectsOutputToContain('SOFT - CG-06, ranked advice')
            ->expectsOutputToContain('[c-density] call_frequency_max, rank 1 - 1 found')
            ->expectsOutputToContain('A sentence the engine generated.')
            ->expectsOutputToContain('COVERAGE - what was measured, and what could not be')
            ->expectsOutputToContain('14 measured, 1 left unjudged')
            ->expectsOutputToContain('A reason the engine generated.')
            ->expectsOutputToContain('engine round trip')
            ->doesntExpectOutputToContain('Nothing was flagged')
            ->assertExitCode(0);
    }

    /**
     * END TO END through the real compiled engine: a month that breaches nothing reports nothing,
     * and the evaluable range is printed so the demo shows the number the whole task turns on.
     */
    public function test_the_real_engine_reports_nothing_on_a_clean_month(): void
    {
        $this->skipWithoutTheBundle();

        $period = $this->seedWorld();
        $this->writeDocument($this->cleanDocument());

        $this->artisan('engine:evaluate', ['period' => EvaluationRequest::periodKey($period), 'document' => $this->documentPath])
            ->expectsOutputToContain('evaluable '.self::TAIL_DUTY_DATE.'..'.self::TO)
            ->expectsOutputToContain('Nothing was flagged.')
            ->expectsOutputToContain('COVERAGE - what was measured, and what could not be')
            ->assertExitCode(0);
    }

    /**
     * P2 TASK 21 FINDING 6, PERFORMED. One world, one field changed.
     *
     * Both halves are handed to the REAL engine. The first is the request this command builds — the
     * day vector and availability over the whole evaluable range — and it reports nothing. The
     * second narrows every person's availability to the period, which is precisely what a caller
     * that built the context over the period and widened the horizon afterwards would produce, and
     * the same clean month comes back with a breach in it.
     *
     * The assertion is deliberately on the CONTENT of the second answer as well as its size: a case
     * that merely counted would pass if the narrowing happened to break something else.
     */
    public function test_availability_for_the_period_alone_fires_on_a_month_that_breaches_nothing(): void
    {
        $this->skipWithoutTheBundle();

        $period = $this->seedWorld();
        $request = EvaluationRequest::forPeriod($period, $this->cleanDocument());

        $honest = $this->runTheEngine($request);

        $this->assertSame([], $honest['violations'], 'this world is supposed to breach nothing');

        $narrowed = $request;

        foreach ($narrowed['context']['people'] as $index => $entry) {
            $narrowed['context']['people'][$index]['eligibleDays'] = array_values(array_filter(
                $entry['eligibleDays'],
                fn (string $date): bool => $date >= self::FROM,
            ));
        }

        $fired = $this->runTheEngine($narrowed)['violations'];

        $this->assertNotSame([], $fired, 'availability for the period alone did NOT fire — re-derive the numbers in this case');

        $first = $fired[0];

        $this->assertSame('c-density', $first['conditionId']);
        $this->assertSame('window', $first['location']['kind']);
        $this->assertSame(self::TAIL_DUTY_DATE, $first['location']['from']);
        $this->assertStringContainsString('available on 1 day', $first['explanation']);
    }

    /**
     * The two cases above skip without the bundle, so this one asserts they cannot silently skip
     * where it counts: CI builds the engine before it runs the PHP suite. A reordering of that
     * workflow would turn both into permanent skips, which on a green run looks exactly like a
     * clean tree — `DeploymentInvariantsTest`'s idiom, applied to a step order.
     */
    public function test_ci_builds_the_bundle_before_the_php_suite(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        $build = strpos($workflow, 'npm run build:engine');
        $suite = strpos($workflow, 'php artisan test');

        $this->assertNotFalse($build, 'CI no longer builds the engine bundle at all.');
        $this->assertNotFalse($suite, 'CI no longer runs the PHP suite.');
        $this->assertLessThan(
            $suite,
            $build,
            'CI runs php artisan test before npm run build:engine, so every case in this file that '
            .'needs the compiled bundle now SKIPS in CI — which reads exactly like a clean tree.'
        );
    }

    private function skipWithoutTheBundle(): void
    {
        if (! is_file(base_path('packages/engine/dist/engine.mjs'))) {
            $this->markTestSkipped('The engine bundle is not built — run `npm run build:engine` first.');
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function runTheEngine(array $request): array
    {
        $result = Process::timeout(300)
            ->input((string) json_encode($request))
            ->run(['node', base_path('packages/engine/bin/evaluate.mjs')]);

        $this->assertSame(0, $result->exitCode(), 'the engine refused this request: '.$result->errorOutput());

        return json_decode($result->output(), true);
    }

    /**
     * The world: one period, one person, and nothing else. Deliberately synthetic and permanently
     * so — the owner points the command at the real rota, and nothing real enters this repository.
     */
    private function seedWorld(): Period
    {
        $period = Period::query()->create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => self::FROM,
            'ends_on' => self::TO,
        ]);

        Person::factory()->create(['joined_at' => null]);

        return $period;
    }

    /**
     * A month that breaches nothing: two calls, 31 days apart, against one call in every three
     * available days averaged over four weeks. Twenty-eight available days permit nine.
     *
     * @return array<string, mixed>
     */
    private function cleanDocument(): array
    {
        $person = Person::query()->first();
        $key = $person === null ? 'p1' : ContextBuilder::personKey($person);

        return [
            'slots' => [[
                'key' => 'night',
                'kind' => 'call',
                'cadence' => 'daily',
                'spanDays' => 1,
                'startMinute' => 1200,
                'endMinute' => 480,
                'crossesMidnight' => true,
                'countsHours' => true,
                'tallyKey' => 'nights',
            ]],
            'duties' => [['personKey' => $key, 'date' => '2026-08-06', 'slotKey' => 'night']],
            'priorDuties' => [['personKey' => $key, 'date' => self::TAIL_DUTY_DATE, 'slotKey' => 'night']],
            'followingDuties' => [],
            'historyAvailableFrom' => '2026-07-01',
            'unwantedDays' => [],
            'conditions' => [[
                'id' => 'c-density',
                'typeKey' => 'call_frequency_max',
                'class' => 'soft',
                'rank' => 1,
                'active' => true,
                'params' => ['n' => 3, 'windowDays' => 28],
            ]],
        ];
    }

    /** @param array<string, mixed> $document */
    private function writeDocument(array $document): void
    {
        file_put_contents($this->documentPath, (string) json_encode($document));
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (['people', 'periods', 'vacations', 'clinics', 'master_rota_assignments', 'person_levels', 'units', 'levels'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
