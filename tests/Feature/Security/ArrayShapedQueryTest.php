<?php

namespace Tests\Feature\Security;

use App\Models\Institution;
use App\Models\Level;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Support\Calendar;
use App\Support\LevelAssignment;
use App\Support\Rota\RotaAssignment;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * `?q[]=x` — ONE DEFECT CLASS, NOT THREE BUGS (P1d-2 adversarial review, finding 1).
 *
 * Every value in `$_GET` and `$_POST` is a string OR AN ARRAY, chosen by whoever typed the URL.
 * `(string) $request->query('q', '')` on an array raises `Array to string conversion`, which
 * `HandleExceptions` promotes to an `ErrorException` and the handler renders as a 500 — no login
 * needed beyond the screen's own capability, no payload, no craft: a resident appending `[]` to a
 * filter name takes a page down. `$request->string('q')` is NOT the fix; it throws on array input
 * too.
 *
 * THE SIBLINGS ALREADY GUARDED, WHICH IS WHY THIS IS A CLASS. `RotaController` reads three query
 * parameters in eleven lines: `year` is behind `is_string()`, `level` behind `is_numeric()`, and
 * `q` behind nothing at all. Two of the three authors of those lines happened to guard. Reviewing
 * one instance and fixing one instance leaves the shape in the codebase for the next screen to
 * copy, so all five sites are asserted here, in one file, from the one property they share: an
 * array-shaped parameter must produce a NEGOTIATED answer — a rendered page with the parameter
 * ignored, or a 422 — never a 500.
 *
 * THREE OF THE FIVE PREDATE THIS SLICE (`Admin/PeriodController`, `Admin/AccessControlController`,
 * and the two `member_email` normalisers, which are a TypeError rather than a string cast but the
 * same unvalidated-shape-into-a-scalar-sink defect). They are fixed here because the guard is two
 * lines each and because a test file named after the class is worth more than three named after
 * the instances.
 *
 * A SANE ECHOED FILTER IS PART OF THE ASSERTION, not just the status code. A guard that swallowed
 * the parameter into `null` where the screen expects `''` would render 200 and then hand the client
 * a prop of the wrong type — which is the same bug one layer along.
 */
class ArrayShapedQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    /**
     * `/rota?q[]=x` — the instance this review found in a browser. `level` and `year` are asserted
     * beside it because a guard added to one parameter of three is how the next one gets missed.
     */
    public function test_the_rota_read_view_survives_an_array_shaped_filter(): void
    {
        $this->seedYear();
        $resident = User::factory()->create(['position' => 4]);

        $props = $this->propsFrom('/rota?year=2026-2027&q[]=x', $resident);

        $this->assertSame('', $props['filters']['q'],
            'an array-shaped `q` must be ignored and echoed back as the empty search the server '
            .'actually applied — never null, which is a different type on the client');
        $this->assertNotNull($props['grid'], 'the array-shaped filter took the grid with it');

        // The two that already guarded, pinned so they stay guarded.
        $props = $this->propsFrom('/rota?year=2026-2027&level[]=1', $resident);
        $this->assertNull($props['filters']['level']);

        $props = $this->propsFrom('/rota?year[]=2026-2027', $resident);
        $this->assertSame('2026-2027', $props['year'],
            'an array-shaped `year` should fall through to the landing year, not blow up or blank');
    }

    /**
     * Pre-existing on `main`. `Calendar::tryParse((string) $request->query('next_year_start', ''))`
     * — the same cast, on the screen that generates a department's whole academic year.
     */
    public function test_the_periods_screen_survives_an_array_shaped_next_year_start(): void
    {
        Institution::current()->update([
            'period_type' => Institution::PERIOD_WEEK_BLOCKS,
            'academic_year_start' => '2026-07-01',
            'block_weeks' => [4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 5],
        ]);
        Calendar::flush();

        $admin = User::factory()->create(['position' => 0]);

        $props = $this->propsFrom('/admin/structure/periods?next_year_start[]=2027-07-01', $admin);

        $this->assertNull($props['next_year_start'],
            'an unusable `next_year_start` must be echoed as "none supplied", the same as omitting it');
        $this->assertNotNull($props['preview'],
            'the array-shaped parameter suppressed the preview the screen exists to show');
    }

    /**
     * Pre-existing on `main`, and it fails differently: `User::find(['1'])` returns a COLLECTION,
     * which is not null, so the null-check below it passes and `$user->getKey()` reaches
     * `Macroable::__call()` as a `BadMethodCallException`. Same 500, no string cast involved —
     * which is why the guard is on the SHAPE of the input rather than on any one sink.
     */
    public function test_the_access_control_screen_survives_an_array_shaped_user_id(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $props = $this->propsFrom('/admin/access-control?user_id[]='.$admin->getKey(), $admin);

        $this->assertNull($props['selectedUser'],
            'an array-shaped `user_id` selects nobody, exactly as an unknown id already does');
    }

    /**
     * The same class one verb along: `Person::normalizeEmail()` is typed `?string`, and both
     * profile writers call it on the raw input BEFORE `validate()` runs — so an array-shaped
     * `member_email` is a TypeError, not a 422. The rules already reject an array; the merge just
     * has to let them.
     */
    public function test_an_array_shaped_email_is_a_validation_error_not_a_type_error(): void
    {
        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'full_name' => 'Someone Real',
                'member_name' => $user->member_name,
                'member_email' => ['x@example.test'],
            ])
            ->assertSessionHasErrors('member_email');

        $admin = User::factory()->create(['position' => 0]);
        $target = User::factory()->create(['position' => 4]);

        $this->actingAs($admin)
            ->from('/admin/users')
            ->patch('/admin/users/'.$target->getKey().'/profile', [
                'full_name' => 'Someone Else',
                'member_name' => $target->member_name,
                'member_email' => ['y@example.test'],
                'position' => 4,
            ])
            ->assertSessionHasErrors('member_email');
    }

    /** @return array<string, mixed> */
    private function propsFrom(string $url, User $viewer): array
    {
        $captured = null;

        $this->actingAs($viewer)->get($url)->assertOk()
            ->assertInertia(function (Assert $page) use (&$captured): void {
                $captured = $page->toArray()['props'];
            });

        $this->assertIsArray($captured);

        return $captured;
    }

    /** One period, one unit, one levelled person — enough for `/rota` to build a real grid. */
    private function seedYear(): void
    {
        $level = Level::factory()->create(['code' => 'XQ1', 'display_order' => 10]);
        $unit = Unit::create(['code' => 'XQU', 'name' => 'Array Query Unit', 'active' => true]);

        $start = CarbonImmutable::parse('2026-07-01');

        $period = Period::create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => $start->format('Y-m-d'),
            'ends_on' => $start->addWeeks(4)->subDay()->format('Y-m-d'),
        ]);

        $person = Person::factory()->create(['full_name' => 'Array Query Fixture']);
        LevelAssignment::assign($person, $level, $start->format('Y-m-d'));
        RotaAssignment::set($person, $period, $unit);
    }
}
