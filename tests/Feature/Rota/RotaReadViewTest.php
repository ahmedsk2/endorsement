<?php

namespace Tests\Feature\Rota;

use App\Models\Institution;
use App\Models\Level;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Support\LevelAssignment;
use App\Support\Rota\RotaAssignment;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Munawib MR-05 — the read view at `/rota`, behind `cap:rota.view`, which every seeded position
 * holds (P1d-1 owner decision 2). Three properties, each asserted rather than assumed.
 *
 * NO PUBLISH GATE (P1d-2 owner decision 1, 2026-08-10). `/rota` always shows the current rota:
 * no `status` column, no `published_at`, no draft state, no "visible from" date. The design doc
 * once listed an explicit "not visible until I say so" gate as an open product option; it is
 * closed, and `test_there_is_no_publish_state_on_the_read_view` is what keeps it closed.
 *
 * CONTACT-FREE FOR EVERY VIEWER (P1d-2 Decision C, closing finding 3). Not "contact-free for a
 * resident" — `test_no_contact_field_reaches_the_props_for_any_viewer` runs the assertion for an
 * ADMINISTRATOR on a department set to `contact_visibility = members`, which is the single most
 * permissive combination this system can produce: `PersonPolicy::viewContact()` is
 * `people.manage OR membersMaySeeContact()`, so both branches are true at once there. A test
 * written against a resident on the default setting would pass with the defect fully present.
 *
 * The defect was real and it was in the EDITOR, before this read view existed:
 * `RotaGrid::forYear()` passed the request's user to `PersonPresenter::one()`, so on a department
 * that had opted members into contact detail, `/admin/rota` shipped every colleague's email and
 * phone number in its Inertia props. Nothing rendered them — which is worse, not better, because
 * a payload disclosure that no screen displays is invisible to review. Hence
 * `test_the_editor_grid_is_contact_free_too`: the fix lands on both surfaces from one edit, and
 * the surface that was already leaking gets its own case.
 */
class RotaReadViewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Props every Inertia page carries from `HandleInertiaRequests::share()`, regardless of the
     * screen. Excluded from the publish-state scan only — `flash.status` is the layout's one-shot
     * banner channel and has nothing to do with a rota's state, and a scan that flagged it would
     * be a guard nobody could keep green. The CONTACT scan deliberately does NOT exclude them: no
     * shared prop carries a contact field today, and if one ever does, this test should say so.
     */
    private const SHARED_PROPS = ['auth', 'nav', 'shift', 'flash', 'errors'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    public function test_a_resident_reaches_the_read_view(): void
    {
        $this->seedYear();

        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/rota?year=2026-2027')->assertOk();
    }

    public function test_the_read_view_is_not_under_admin_and_the_editor_still_is(): void
    {
        $this->seedYear();

        $resident = User::factory()->create(['position' => 4]);

        // MR-05 is a reading act; MR-02 is a scheduling one. Two capabilities, two URL spaces
        // (P1d-1 Decision A) — and after P1d-2 Task 1, `rota.manage` is administrator-only.
        $this->actingAs($resident)->get('/rota')->assertOk();
        $this->actingAs($resident)->get('/admin/rota')->assertForbidden();
    }

    /**
     * Decision C's strong form. The institution is set to the MOST permissive contact setting and
     * the viewer is an administrator holding `people.manage`, so BOTH branches of
     * `PersonPolicy::viewContact()` are true — and the props must still carry neither field.
     */
    public function test_no_contact_field_reaches_the_props_for_any_viewer(): void
    {
        [, $person] = $this->seedYear();

        Institution::current()->update(['contact_visibility' => Institution::CONTACT_MEMBERS]);
        $this->assertTrue(
            \App\Support\ContactVisibility::membersMaySeeContact(),
            'the institution fixture is not actually on CONTACT_MEMBERS — this test would prove nothing'
        );

        foreach ([4, 0] as $position) {
            $viewer = User::factory()->create(['position' => $position]);
            $props = $this->propsFrom($this->actingAs($viewer)->get('/rota?year=2026-2027'));

            // The row must actually be there, or "no contact field" is vacuously true.
            $this->assertContains(
                $person->getKey(),
                array_map(fn (array $row): int => $row['person']['id'], $props['grid']['rows']),
                "position {$position} did not receive the seeded person's row at all"
            );

            $this->assertSame([], $this->keyPaths($props, ['email', 'phone']),
                "position {$position} received a contact field in the /rota props");
        }
    }

    /**
     * The half that fails TODAY (finding 3). `/admin/rota` is the surface that already leaks, and
     * it leaks hardest for exactly this viewer.
     */
    public function test_the_editor_grid_is_contact_free_too(): void
    {
        [, $person] = $this->seedYear();

        Institution::current()->update(['contact_visibility' => Institution::CONTACT_MEMBERS]);

        $admin = User::factory()->create(['position' => 0]);
        $props = $this->propsFrom($this->actingAs($admin)->get('/admin/rota?year=2026-2027'));

        $this->assertContains(
            $person->getKey(),
            array_map(fn (array $row): int => $row['person']['id'], $props['grid']['rows']),
        );

        $this->assertSame([], $this->keyPaths($props, ['email', 'phone']),
            'the editor grid shipped a contact field in its Inertia props');
    }

    /**
     * Owner decision 1 (2026-08-10): there is no publish gate, so there is nothing anywhere in
     * these props that could carry one. Asserted over the whole page rather than over a key
     * somebody remembered to look at, because the way a draft state arrives is by a later task
     * adding it to a nested prop nobody was watching.
     */
    public function test_there_is_no_publish_state_on_the_read_view(): void
    {
        $this->seedYear();

        $resident = User::factory()->create(['position' => 4]);
        $props = $this->propsFrom($this->actingAs($resident)->get('/rota?year=2026-2027'));

        foreach (self::SHARED_PROPS as $shared) {
            unset($props[$shared]);
        }

        $this->assertSame([], $this->keyPaths($props, ['status', 'published', 'published_at', 'draft']),
            'MR-05 has no publish gate (owner decision 1, 2026-08-10) — the read view always shows '
            .'the current rota');
    }

    /**
     * Every string key anywhere in the tree matching one of $needles, as a dotted path. Walking
     * the whole tree is the point: a future presenter change that leaked through a row this test
     * did not think to look at would still be caught.
     *
     * @param  list<string>  $needles
     * @return list<string>
     */
    private function keyPaths(mixed $node, array $needles, string $path = ''): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];

        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key) && in_array($key, $needles, true)) {
                $found[] = $here;
            }

            $found = array_merge($found, $this->keyPaths($value, $needles, $here));
        }

        return $found;
    }

    /** @return array<string, mixed> */
    private function propsFrom(\Illuminate\Testing\TestResponse $response): array
    {
        $captured = null;

        $response->assertOk()->assertInertia(function (Assert $page) use (&$captured): void {
            $captured = $page->toArray()['props'];
        });

        $this->assertIsArray($captured);

        return $captured;
    }

    /**
     * One period, one unit, one levelled person holding a whole-period assignment — and that
     * person carries BOTH contact fields, because a fixture with a null phone would make the
     * contact assertions pass for the wrong reason.
     *
     * `XR`-prefixed codes: `Level::factory()->create(['code' => 'R1'])` collides with
     * `ReferenceSeeder`'s seeded ladder (P1c Task 3's recorded trap).
     *
     * @return array{0: Period, 1: Person}
     */
    private function seedYear(): array
    {
        $level = Level::factory()->create(['code' => 'XR1', 'display_order' => 10]);
        $unit = Unit::create(['code' => 'XRU', 'name' => 'Rota Read Unit', 'active' => true]);

        $start = CarbonImmutable::parse('2026-07-01');

        $period = Period::create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => $start->format('Y-m-d'),
            'ends_on' => $start->addWeeks(4)->subDay()->format('Y-m-d'),
        ]);

        $person = Person::factory()->create([
            'full_name' => 'Read View Fixture',
            'email' => 'fixture@example.test',
            'phone' => '0500000000',
        ]);

        LevelAssignment::assign($person, $level, $start->format('Y-m-d'));
        RotaAssignment::set($person, $period, $unit);

        return [$period, $person];
    }
}
