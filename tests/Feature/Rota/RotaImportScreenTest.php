<?php

namespace Tests\Feature\Rota;

use App\Models\AuditLog;
use App\Models\Level;
use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vacation;
use App\Support\LevelAssignment;
use App\Support\Rota\RotaImport;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Admin → Rota import, the SCREEN and its three routes (P1d-2 Task 12). The engine is
 * `RotaImportTest`'s subject; this file is only about what a controller is for.
 *
 * THE CONTROLLER IS THINNER THAN `RosterImportController`, DELIBERATELY, and Task 11's amendment
 * is why. `RosterImport` has no digest logic at all — its controller computes the sha256 and
 * throws the `ValidationException` — but transposing that here would have put the importer's
 * central safety property one task LATER than the code it protects. So `RotaImport::digest()` is
 * one definition used by both entry points, `commit()` takes the operator's claim and refuses
 * inside its own transaction with `StaleImportFileException`, and this controller does exactly
 * two things with it: map that ONE exception to a 422 on `file`, and write no audit row at all,
 * because `commit()` appends `rota_import` itself after its transaction (finding 8). A pin a
 * controller can forget to apply is not a pin.
 *
 * THE KIND IS AN ENUM ON THE REQUEST, NEVER SNIFFED FROM THE HEADERS. The two files share every
 * column name they have in common (`short_name`, `full_name`, `starts_on`, `ends_on`), so a
 * header sniff would read a leave file as an assignments file missing four columns — or worse,
 * the other way. A file whose shape is guessed is a file that gets misread.
 *
 * THE FLASH KEYS MUST BE SHARED. `back()->with(...)` puts the analysis in the session flash and
 * Inertia does not forward session flash to a page on its own — Task 9's amendment records a
 * whole feature that reached no screen for exactly this reason, with all four suites green. Both
 * assertions below therefore go through a REAL second request rather than reading `session()`.
 */
class RotaImportScreenTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = __DIR__.'/../../fixtures/rota';

    private const YEAR = '2026-2027';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);

        Level::factory()->create(['code' => 'XI1', 'display_order' => 10]);

        $this->admin = User::factory()->create(['position' => 0]);

        // `X`-prefixed codes: a factory-made `PICU`/`R1` collides with ReferenceSeeder's own rows.
        Unit::create(['code' => 'XIU', 'name' => 'Rota Import Unit', 'active' => true]);
        Unit::create(['code' => 'XIW', 'name' => 'Rota Import Ward', 'active' => true]);

        Period::create([
            'academic_year' => self::YEAR, 'kind' => Period::WEEK_BLOCK, 'position' => 1,
            'label' => 'Block 1', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-28',
        ]);
        Period::create([
            'academic_year' => self::YEAR, 'kind' => Period::WEEK_BLOCK, 'position' => 2,
            'label' => 'Block 2', 'starts_on' => '2026-07-29', 'ends_on' => '2026-08-25',
        ]);

        $this->person('Nadia Import', 'import.one');
        $this->person('Yusuf Import', 'import.two');
    }

    // --- the gate -----------------------------------------------------------------------------

    public function test_an_administrator_reaches_the_import_screen(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/rota/import')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/RotaImport'));
    }

    /**
     * `cap:rota.manage`, matching Task 10's export decision: a whole-year overwrite is at least as
     * administrative as a whole-year extraction. Every route, not only the screen — a 403 on the
     * page with an open POST underneath it is the shape this asserts against.
     */
    public function test_every_import_route_is_refused_without_rota_manage(): void
    {
        foreach ([4, 5] as $position) {
            $actor = User::factory()->create(['position' => $position]);

            $this->actingAs($actor)->get('/admin/rota/import')->assertForbidden();

            $this->actingAs($actor)->post('/admin/rota/import/preview', [
                'kind' => RotaImport::KIND_ASSIGNMENTS,
                'file' => $this->uploaded('assignments-clean.csv'),
            ])->assertForbidden();

            $this->actingAs($actor)->post('/admin/rota/import/commit', [
                'kind' => RotaImport::KIND_ASSIGNMENTS,
                'file' => $this->uploaded('assignments-clean.csv'),
                'digest' => str_repeat('a', 64),
            ])->assertForbidden();
        }

        $this->assertSame(0, MasterRotaAssignment::query()->count());
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/rota/import')->assertRedirect('/login');
    }

    // --- preview ------------------------------------------------------------------------------

    /**
     * The dry run writes NOTHING — no row, no audit — and it reaches a SCREEN, which is a
     * different claim from "the controller flashed it" and the only one that is the feature.
     */
    public function test_the_preview_writes_nothing_and_reaches_the_screen_as_a_shared_prop(): void
    {
        $auditBefore = AuditLog::query()->count();

        $this->actingAs($this->admin)->post('/admin/rota/import/preview', [
            'kind' => RotaImport::KIND_ASSIGNMENTS,
            'file' => $this->uploaded('assignments-clean.csv'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, MasterRotaAssignment::query()->count(), 'the preview wrote a row');
        $this->assertSame($auditBefore, AuditLog::query()->count(), 'the preview wrote an audit row');

        $this->actingAs($this->admin)
            ->get('/admin/rota/import')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Three cells from four lines — finding 12's whole point, asserted at the props
                // layer so a controller that flattened the analysis back to one row per line
                // would fail here rather than only in the engine's own test.
                ->has('flash.rota_import_preview.outcomes', 3)
                ->where('flash.rota_import_preview.kind', RotaImport::KIND_ASSIGNMENTS)
                ->has('flash.rota_import_preview.digest')
                ->has('flash.rota_import_preview.summary')
                // Decision C at the props layer: a payload built from whole Eloquent models is
                // how a contact field reaches a page nobody meant to put it on.
                ->missing('flash.rota_import_preview.context')
            );
    }

    /**
     * The digest the screen posts back is the digest of the bytes it previewed — asserted against
     * `RotaImport::digest()` rather than against a re-typed `hash('sha256', …)` at the call site,
     * because a second definition of the pin is the drift this whole shape exists to remove.
     */
    public function test_the_preview_returns_the_digest_of_the_bytes_it_parsed(): void
    {
        $this->actingAs($this->admin)->post('/admin/rota/import/preview', [
            'kind' => RotaImport::KIND_ASSIGNMENTS,
            'file' => $this->uploaded('assignments-clean.csv'),
        ])->assertRedirect();

        $this->assertSame(
            RotaImport::digest($this->bytes('assignments-clean.csv')),
            session('rota_import_preview')['digest'],
        );
    }

    // --- commit -------------------------------------------------------------------------------

    public function test_the_commit_applies_the_file_and_the_result_reaches_the_screen(): void
    {
        $this->commit('assignments-clean.csv', RotaImport::KIND_ASSIGNMENTS)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(4, MasterRotaAssignment::query()->count(), 'four spans across three cells');

        $this->flushHeaders();

        $this->actingAs($this->admin)
            ->get('/admin/rota/import')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.rota_import_result.applied', 3)
                ->where('flash.rota_import_result.kind', RotaImport::KIND_ASSIGNMENTS)
                ->missing('flash.rota_import_result.context')
            );
    }

    /**
     * ONE audit row, and this controller wrote NONE of it. `RotaImport::commit()` appends
     * `rota_import` itself, after its transaction — so a controller that "helpfully" added its own
     * row would double every import in the trail, which this asserts by count and by detail.
     */
    public function test_the_controller_adds_no_audit_row_of_its_own(): void
    {
        $this->commit('assignments-clean.csv', RotaImport::KIND_ASSIGNMENTS)->assertRedirect();

        $this->assertSame(
            ['file=assignments;created=3;replaced=0;unchanged=0;skipped=0;errors=0'],
            AuditLog::query()->where('action', 'rota_import')->pluck('detail')->all(),
        );
    }

    public function test_the_vacations_file_imports_through_the_same_screen(): void
    {
        $this->commit('vacations-clean.csv', RotaImport::KIND_VACATIONS)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Vacation::query()->count());
        $this->assertSame(0, MasterRotaAssignment::query()->count(), 'a leave file wrote an assignment');
    }

    // --- the pin ------------------------------------------------------------------------------

    /**
     * THE STALE DIGEST, at the route. `RotaImportTest` proves `commit()` throws; this proves the
     * operator SEES it — a 422 on `file`, in the exception's own words, with nothing written.
     * Choosing the field by matching an error message's text is the drift the typed exception
     * exists to remove, so the mapping is asserted here rather than assumed.
     */
    public function test_a_file_changed_since_the_preview_is_refused_on_the_file_field(): void
    {
        $this->actingAs($this->admin)->post('/admin/rota/import/preview', [
            'kind' => RotaImport::KIND_ASSIGNMENTS,
            'file' => $this->uploaded('assignments-clean.csv'),
        ])->assertRedirect();

        $stale = session('rota_import_preview')['digest'];

        $response = $this->actingAs($this->admin)->post('/admin/rota/import/commit', [
            'kind' => RotaImport::KIND_ASSIGNMENTS,
            // A DIFFERENT file, carrying the previous one's digest.
            'file' => $this->uploaded('assignments-unknown-person.csv'),
            'digest' => $stale,
        ]);

        $response->assertSessionHasErrors('file');

        $this->assertStringContainsString(
            'preview',
            (string) session('errors')->first('file'),
            'the refusal must name the fix — preview it again',
        );

        $this->assertSame(0, MasterRotaAssignment::query()->count(), 'a stale commit wrote rows');
        $this->assertSame(0, AuditLog::query()->where('action', 'rota_import')->count());
    }

    public function test_a_commit_with_no_digest_at_all_is_refused(): void
    {
        $this->actingAs($this->admin)->post('/admin/rota/import/commit', [
            'kind' => RotaImport::KIND_ASSIGNMENTS,
            'file' => $this->uploaded('assignments-clean.csv'),
        ])->assertSessionHasErrors('digest');

        $this->assertSame(0, MasterRotaAssignment::query()->count());
    }

    // --- the file itself ----------------------------------------------------------------------

    /**
     * A file-level problem refuses the WHOLE import — never "7 of 8" — and says so on the field
     * the operator can act on. The per-file-error sentences are rendered from the analysis; this
     * is the 422 that stops the commit reaching the screen as a success.
     */
    public function test_a_file_level_error_refuses_the_commit_on_the_file_field(): void
    {
        $this->commit('assignments-bad-headers.csv', RotaImport::KIND_ASSIGNMENTS)
            ->assertSessionHasErrors('file');

        $this->assertSame(0, MasterRotaAssignment::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'rota_import')->count());
    }

    /**
     * The kind is validated as an enum on BOTH routes. `RotaImport::analyse()` throws an
     * `InvalidArgumentException` for an unknown kind — a programming error, and a 500 if it ever
     * reached the engine from a request body.
     */
    public function test_an_unknown_kind_is_refused_on_both_routes_rather_than_reaching_the_engine(): void
    {
        foreach (['/admin/rota/import/preview', '/admin/rota/import/commit'] as $url) {
            $this->actingAs($this->admin)->post($url, [
                'kind' => 'roster',
                'file' => $this->uploaded('assignments-clean.csv'),
                'digest' => str_repeat('a', 64),
            ])->assertSessionHasErrors('kind');
        }

        $this->assertSame(0, MasterRotaAssignment::query()->count());
    }

    public function test_a_missing_file_is_refused_on_the_file_field(): void
    {
        $this->actingAs($this->admin)->post('/admin/rota/import/preview', [
            'kind' => RotaImport::KIND_ASSIGNMENTS,
        ])->assertSessionHasErrors('file');
    }

    // --- helpers ------------------------------------------------------------------------------

    private function commit(string $fixture, string $kind): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post('/admin/rota/import/commit', [
            'kind' => $kind,
            'file' => $this->uploaded($fixture),
            'digest' => RotaImport::digest($this->bytes($fixture)),
        ]);
    }

    private function uploaded(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->bytes($name));
    }

    private function bytes(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.'/'.$name);
    }

    private function person(string $fullName, string $shortName): Person
    {
        $person = Person::factory()->create(['full_name' => $fullName, 'short_name' => $shortName]);

        LevelAssignment::assign($person, Level::query()->where('code', 'XI1')->firstOrFail(), '2026-07-01');

        return $person;
    }
}
