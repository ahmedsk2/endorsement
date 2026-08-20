<?php

namespace Tests\Feature\Build;

use App\Models\Institution;
use App\Models\Level;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\Vacation;
use App\Support\Calendar;
use App\Support\Clinics\ClinicWriter;
use App\Support\Engine\ContextBuilder;
use App\Support\LevelAssignment;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\VacationBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SourceScanner;
use Tests\TestCase;

/**
 * P2 Decision I, second half: NO RULE LIVES IN THE SERIALISER.
 *
 * ## THE FAILURE THIS EXISTS FOR, AND WHY `RulesLiveOnlyInTheEngineTest` CANNOT SEE IT
 *
 * That guard fails the build on a PHP file that names a catalog type key, a severity or a
 * violation — a rule written as rules. This one watches the other shape, the one that arrives as an
 * optimisation and names nothing at all: **sending only the people who could legally take a duty.**
 * That is one catalog type re-implemented as a `where`, it contains none of the vocabulary the
 * sibling guard looks for, and it fails in the direction nobody sees — the engine reports no
 * finding, because the row it would have flagged never crossed the contract.
 *
 * A rule written as a query is worse than a rule written as a rule. A PHP rule is at least
 * reviewable beside the TypeScript one and would eventually be found to disagree with it; a
 * silently narrowed population produces a green evaluation on a draft that has something wrong with
 * it, and nothing anywhere is comparing two answers.
 *
 * ## TWO HALVES, BECAUSE EITHER ALONE STAYS GREEN WHILE THE OTHER ROTS
 *
 *  1. **The source half** — a `where`-family call whose column is a fact a CONDITION judges, plus
 *     the four shapes a "send only what matters" narrowing actually takes. Same
 *     regex idiom as `InstitutionProvenanceTest::test_no_query_filters_on_institution_id`, and for
 *     the same reason: a plain substring cannot tell `'external' => (bool) $person->external`, a
 *     legitimate projection this loader performs, from `where('external', false)`, which is a rule.
 *  2. **The behavioural half** — the built context is asserted to DESCRIBE every person a condition
 *     would exclude. This is the load-bearing one. A narrowing written with a needle nobody thought
 *     of, or performed in PHP after the query, is invisible to any scan and is exactly what the
 *     fixture below catches: a roster of eight people every one of whom some condition type has a
 *     reason to drop, and every one of whom must arrive.
 *
 * ## THE TWO HALVES COVER DIFFERENT POPULATIONS, AND THAT WAS FOUND BY A GREEN PLANT
 *
 * `where('external', false)` was planted on the roster query. The source half named it; the
 * BEHAVIOURAL half stayed GREEN — because `ContextBuilder`'s stranded-span union re-adds anybody
 * who still holds a rotation, and the fixture's external person holds one. So the behavioural half
 * cannot see a narrowed active-roster query at all for the people the union rescues, and the source
 * half cannot see a narrowing performed in PHP after the query. Planting the second shape — a
 * `filter()` over the loaded collection keeping only people with a rotation — reversed it exactly:
 * the source half green, the behavioural half red, naming the one person no union can rescue.
 *
 * Neither half is redundant and neither is sufficient. Both were watched failing on the shape the
 * other is blind to, which is the only way this was going to be known.
 *
 * ## WHAT THE SOURCE HALF DELIBERATELY DOES NOT NEEDLE, AND THE ONE NARROWING THAT IS ALLOWED
 *
 * `Person::query()->active()` is the one population narrowing this loader performs, and it is a
 * SCOPE rather than a quoted column so no regex here would reach it anyway. It stays, and it is not
 * a rule: `people.active` means *may be NAMED* — roster membership, the department's own answer to
 * who exists — and no condition type judges it. The honest defence of that line is not a needle but
 * the behavioural half below, where a person who has left the active roster while still holding a
 * rotation is asserted present, because THAT is the case where "active" and "described" come apart.
 *
 * `where('starts_on', …)` / `where('ends_on', …)` / `whereIn('person_id', …)` are the horizon and
 * the roster, and narrowing by the horizon is what a horizon is. They are not needled and could not
 * usefully be.
 *
 * ## THE COLUMN LIST, MEASURED (ruling 42)
 *
 * Each column below is a field the CG-10 contract carries for the engine to judge, and each matches
 * ZERO where-family calls in the scanned namespace today, so the allow-list is empty and nothing
 * had to be excused into it. `active` is on the list although the roster scope above exists,
 * because the needle is the QUOTED-COLUMN form and the scope is not: a future
 * `Clinic::query()->where('active', true)` would be the same defect one table along — the CG-10
 * `Clinic` shape carries `active` precisely so the engine can decide what a retired clinic means,
 * and a loader that filtered them out would have taken that decision away silently.
 *
 * Comments are stripped, per `SourceScanner`'s discipline and this phase's most-repeated finding —
 * this file's own prose above contains `where('external', false)` as the example of the defect, and
 * a raw scan of a file explaining the rule would fail the build on the explanation.
 */
class EngineHoldsNoRuleTest extends TestCase
{
    use RefreshDatabase;

    private const ENGINE_GLOB = 'app/Support/Engine/*.php';

    /**
     * Facts a CONDITION judges. A loader that narrows on one of these has taken the decision away
     * from the engine and reported nothing about it.
     *
     * @var list<string>
     */
    private const JUDGED_COLUMNS = [
        'external',
        'joined_at',
        'equity_tracked',
        'call_target',
        'training_rotation',
        'attendee_mode',
        'granularity',
        'active',
    ];

    /**
     * The shapes a narrowing takes that no column list would catch. Each measured at ZERO in the
     * scanned namespace before being bought.
     *
     * `limit(`/`take(` are here because the quietest possible version of this defect is a loader
     * that sends the first fifty people; `whereHas(`/`whereDoesntHave(`/`whereNotIn(` are how one
     * would express *"only those with a rotation"*, which is the availability denominator's third
     * clause turned into a filter — the exact inference MR-04 refuses and that `ContextBuilder`
     * states out loud that it does not make.
     *
     * @var list<string>
     */
    private const NARROWING_NEEDLES = [
        'whereNotIn(',
        'whereHas(',
        'whereDoesntHave(',
        '->having(',
        '->limit(',
        '->take(',
    ];

    /** @var array<string, list<string>> */
    private const ALLOW_LIST = [];

    /** @return list<string> */
    private function engineFiles(): array
    {
        return glob(base_path(self::ENGINE_GLOB)) ?: [];
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }

    public function test_the_loader_never_narrows_on_something_a_condition_judges(): void
    {
        $offenders = [];
        $files = $this->engineFiles();

        $this->assertContains(
            'app/Support/Engine/ContextBuilder.php',
            array_map(fn (string $path): string => $this->relative($path), $files),
            'The glob found no context builder — a guard scanning nothing looks exactly like a '
            .'clean tree.'
        );

        foreach ($files as $path) {
            $relative = $this->relative($path);
            $exempt = self::ALLOW_LIST[$relative] ?? [];
            $code = SourceScanner::withoutComments($path);

            foreach (self::JUDGED_COLUMNS as $column) {
                if (in_array($column, $exempt, true)) {
                    continue;
                }

                // The `where`-family idiom InstitutionProvenanceTest already proved out: both the
                // plain-argument form and the array form, case-insensitive so `orWhere(` and
                // `firstWhere(` are caught and not just a lowercase prefix.
                if (preg_match('/where\w*\(\s*(\[[^\]]*)?[\'"]'.preg_quote($column, '/').'[\'"]/i', $code)) {
                    $offenders[] = "{$relative} filters on \"{$column}\"";
                }
            }

            foreach (self::NARROWING_NEEDLES as $needle) {
                if (in_array($needle, $exempt, true)) {
                    continue;
                }

                if (str_contains($code, $needle)) {
                    $offenders[] = "{$relative} names \"{$needle}\"";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "P2 Decision I: the context builder narrows by the HORIZON and by nothing else.\n"
            ."Sending only the people, dates, clinics or slots that could produce a finding is a\n"
            ."catalog rule re-implemented as a query — and it fails silently, because the engine\n"
            ."then reports nothing about the row it never received. Whatever a condition judges\n"
            ."must ARRIVE, described, for the engine to judge or not judge. Found:\n"
            .implode("\n", $offenders)
        );
    }

    /**
     * The regex above is only as good as its ability to fire. `ClinicPickers` and every controller
     * in this application narrow on `active`; the pattern is calibrated against a real file so a
     * malformed one cannot pass by matching nothing at all.
     */
    public function test_the_column_pattern_can_actually_see_a_filter(): void
    {
        $code = SourceScanner::withoutComments(base_path('app/Support/Calendar.php'));

        $this->assertMatchesRegularExpression(
            '/where\w*\(\s*(\[[^\]]*)?[\'"]active[\'"]/i',
            $code,
            'Calendar::activeHolidays() no longer narrows on "active", so this calibration proves '
            .'nothing about the pattern. Point it at a file that does.'
        );
    }

    /**
     * THE BEHAVIOURAL HALF, and the load-bearing one.
     *
     * Eight people, every one of whom some condition type has a reason to drop, and a clinic
     * nobody runs any more. Every one must arrive DESCRIBED. A narrowing performed in PHP after the
     * query, or written with a needle nobody thought of, is invisible to the scan above and lands
     * here.
     *
     * `assertSame` over the whole SET rather than eight `assertContains` calls: the property is
     * that nothing was dropped, and a per-person loop stops guarding the moment somebody's
     * expectation is edited to match what the code now returns.
     */
    public function test_the_context_describes_every_person_a_condition_would_exclude(): void
    {
        Institution::query()->create([
            'code' => 'QCH', 'name' => 'Test Institution', 'active' => true, 'weekend_days' => [5, 6],
        ]);
        Calendar::flush();

        $period = Period::query()->create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => '2026-08-02',
            'ends_on' => '2026-08-15',
        ]);

        $unit = Unit::query()->create([
            'code' => 'XNR', 'name' => 'No Rule Unit', 'active' => true, 'clinic_owner' => true,
        ]);
        $level = Level::factory()->create(['code' => 'XNR1', 'display_order' => 10]);

        $expected = [];

        // 1. Ordinary. The control — without one, "everybody was dropped" would pass too.
        $ordinary = Person::factory()->create();
        LevelAssignment::assign($ordinary, $level, '2026-01-01');
        RotaAssignment::set($ordinary, $period, $unit);
        $expected[] = ContextBuilder::personKey($ordinary);

        // 2. On leave for the ENTIRE horizon. `vacation_block` would refuse every duty they hold,
        //    and `fairness_distribution` pro-rates their share to zero — neither is a reason not to
        //    describe them, and a loader that dropped them would make both types silently agree
        //    that there is nothing to say.
        $onLeave = Person::factory()->create();
        LevelAssignment::assign($onLeave, $level, '2026-01-01');
        RotaAssignment::set($onLeave, $period, $unit);
        VacationBooking::book($onLeave, '2026-08-02', '2026-08-15', Vacation::GRANULARITY_DATE);
        $expected[] = ContextBuilder::personKey($onLeave);

        // 3. No rotation at all. The availability denominator's third clause would remove their
        //    days if it were implemented from the rota — which is the inference MR-04 refuses.
        $unrotated = Person::factory()->create();
        LevelAssignment::assign($unrotated, $level, '2026-01-01');
        $expected[] = ContextBuilder::personKey($unrotated);

        // 4. No level history. `eligibility`, `count_max`'s level filter and `composition` all read
        //    a level at a date and get null here; that is a state, not a reason to vanish.
        $unlevelled = Person::factory()->create();
        RotaAssignment::set($unlevelled, $period, $unit);
        $expected[] = ContextBuilder::personKey($unlevelled);

        // 5. No join date. Owner decision T makes this NO violation and REPORTED — which the engine
        //    can only do for somebody it was told about.
        $unjoined = Person::factory()->create(['joined_at' => null]);
        RotaAssignment::set($unjoined, $period, $unit);
        $expected[] = ContextBuilder::personKey($unjoined);

        // 6. Joins after the horizon ends. Every one of their eligible days is empty and every duty
        //    they hold is before they start — which is the case `onboarding_grace` exists to flag.
        $future = Person::factory()->create(['joined_at' => '2027-01-01']);
        RotaAssignment::set($future, $period, $unit);
        $expected[] = ContextBuilder::personKey($future);

        // 7. External. `fairness_distribution`'s `excludeExternal` defaults TRUE, so this is the
        //    person a loader is likeliest to drop "because the rule excludes them anyway" — and the
        //    flag is a parameter of ONE type, not a property of the roster.
        $external = Person::factory()->create(['external' => true]);
        LevelAssignment::assign($external, $level, '2026-01-01');
        RotaAssignment::set($external, $period, $unit);
        $expected[] = ContextBuilder::personKey($external);

        // 8. Left the roster, still holds a rotation. The one case where "active" and "described"
        //    come apart — and a duty naming somebody the context does not describe THROWS, so
        //    dropping them kills the whole evaluation rather than one row of it.
        $departed = Person::factory()->create();
        RotaAssignment::set($departed, $period, $unit);
        $departed->forceFill(['active' => false])->save();
        $expected[] = ContextBuilder::personKey($departed);

        $retired = ClinicWriter::create($unit, ['name' => 'Retired', 'weekday' => 2, 'session' => 'AM']);
        ClinicWriter::setActive($retired, false);

        $context = ContextBuilder::forHorizon('2026-08-02', '2026-08-15');

        sort($expected);
        $described = array_column($context['people'], 'key');
        sort($described);

        $this->assertSame(
            $expected,
            $described,
            'The context dropped somebody a condition would have judged. Narrowing the population '
            .'to what could produce a finding is that condition re-implemented as a query, and it '
            .'fails silently: the engine reports nothing about a row it never received.'
        );

        $this->assertSame(
            ['c'.$retired->getKey()],
            array_column($context['clinics'], 'key'),
            'A deactivated clinic was filtered out. Whether a retired clinic can produce a finding '
            .'is the engine\'s call — the contract carries `active` for exactly that reason.'
        );

        $this->assertSame(
            Calendar::datesBetween('2026-08-02', '2026-08-15'),
            array_column($context['days'], 'date'),
            'A date was dropped from the day vector. Weekends and holidays are days a condition '
            .'judges, not days a loader may decide are uninteresting.'
        );
    }
}
