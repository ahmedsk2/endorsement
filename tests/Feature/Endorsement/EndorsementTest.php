<?php

namespace Tests\Feature\Endorsement;

use App\Models\AuditLog;
use App\Models\Handover;
use App\Models\Unit;
use App\Models\UnitFieldDefinition;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * C2 (narrowed by G1) — the shift-endorsement / handover module, now PICU-ONLY. The `{unit}` route
 * param survives for URL stability but accepts PICU alone; NICU / SCBU / WARD 404. View is
 * `endorsement.view`; every write is `endorsement.edit` (legacy gate [0,2,3,4] — excludes Nurse).
 * The four rich-text fields are sanitized on write by the model cast (stored-XSS defense) and every
 * write is audited PHI-free.
 */
class EndorsementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    /** An editor: Charge Nurse (2) holds endorsement.view + endorsement.edit. */
    private function editor(): User
    {
        return User::factory()->create(['position' => 2]);
    }

    /** A viewer with no edit right is not available in the seeded matrix (view implies edit for
     * every role that has it), so an Administrator stands in for "authorized". */
    private function admin(): User
    {
        return User::factory()->create(['position' => 0]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function handover(string $unitCode, string $date, array $overrides = []): Handover
    {
        $unit = Unit::where('code', $unitCode)->firstOrFail();

        return Handover::create(array_merge([
            'unit_id' => $unit->id,
            'handover_date' => $date,
            'bed' => '1',
            'mrn' => 'M-'.fake()->unique()->numerify('#####'),
            'patient_name' => 'Test Child',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function defineField(string $unitCode, string $key, array $overrides = []): UnitFieldDefinition
    {
        $unit = Unit::where('code', $unitCode)->firstOrFail();

        return UnitFieldDefinition::create(array_merge([
            'unit_id' => $unit->id,
            'key' => $key,
            'label' => ucfirst($key),
            'type' => 'text',
        ], $overrides));
    }

    public function test_index_lists_handover_dates_for_a_unit(): void
    {
        $this->handover('PICU', '2026-07-10');
        $this->handover('PICU', '2026-07-11');
        // Another unit's day must NOT bleed into the PICU index (unit partition).
        $nicu = Unit::where('code', 'NICU')->firstOrFail();
        Handover::create([
            'unit_id' => $nicu->id,
            'handover_date' => '2026-07-12',
            'bed' => '1',
            'mrn' => 'RETAINED-1',
            'patient_name' => 'Retained Child',
        ]);

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Endorsement/Index')
                ->where('unit.code', 'PICU')
                ->has('dates', 2)
            );
    }

    /**
     * Task 6 / Decision A — the server, not the browser, decides what a "missing day" is. Each
     * `dates` entry is dual-dated (UX-04), and the merged day/gap listing (spec §10.4) is
     * computed here so `resources/js` performs no date arithmetic at all — no `new Date()`, no
     * `toISOString()` re-deriving "the day between these two" in the browser's own timezone.
     */
    public function test_index_dual_dates_each_day_and_precomputes_the_gap_listing(): void
    {
        // 2026-07-10 .. 2026-07-14: a 3-day gap (07-11, 07-12, 07-13), under the GAP_RENDER_LIMIT
        // of 7, so each missing day is listed individually rather than collapsed to a summary.
        $this->handover('PICU', '2026-07-10');
        $this->handover('PICU', '2026-07-14');

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Endorsement/Index')
                ->has('dates', 2)
                // Every day entry is dual-dated — the calculation itself is pinned by
                // CalendarTest; this only proves the controller wires it through.
                ->where('dates.0.date', '2026-07-14')
                ->where('dates.0.hijri', fn (mixed $v): bool => is_string($v) && $v !== '')
                ->where('dates.0.weekend', fn (mixed $v): bool => is_bool($v))
                // The merged listing: day(07-14), gap(07-13), gap(07-12), gap(07-11), day(07-10)
                // — newest first, matching the removed client computation exactly.
                ->has('listing', 5)
                ->where('listing.0.type', 'day')
                ->where('listing.0.date', '2026-07-14')
                ->where('listing.1.type', 'gap')
                ->where('listing.1.date', '2026-07-13')
                ->where('listing.2.date', '2026-07-12')
                ->where('listing.3.date', '2026-07-11')
                ->where('listing.4.type', 'day')
                ->where('listing.4.date', '2026-07-10')
            );
    }

    /** The GAP_RENDER_LIMIT collapse (spec §10.4), now computed server-side. */
    public function test_index_collapses_a_long_gap_into_one_summary_listing_entry(): void
    {
        $this->handover('PICU', '2026-07-01');
        $this->handover('PICU', '2026-07-20'); // 18 missing days between them

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('listing', 3) // day(07-20), gap-summary, day(07-01)
                ->where('listing.1.type', 'gap-summary')
                ->where('listing.1.count', 18)
                ->where('listing.1.from', '2026-07-01')
                ->where('listing.1.to', '2026-07-20')
            );
    }

    public function test_index_requires_endorsement_view_capability(): void
    {
        // Nurse (1) holds no endorsement.* capability (legacy endorsement gate excludes Nurse).
        $nurse = User::factory()->create(['position' => 1]);

        $this->actingAs($nurse)->get('/endorsement/PICU')->assertForbidden();
    }

    public function test_index_redirects_a_guest_to_login(): void
    {
        $this->get('/endorsement/PICU')->assertRedirect('/login');
    }

    public function test_an_unknown_unit_code_is_rejected(): void
    {
        $this->actingAs($this->admin())->get('/endorsement/FOO')->assertNotFound();
    }


    /** G1 — the lowercase PICU URL keeps working (the legacy links and the nav both use it). */
    public function test_the_lowercase_picu_url_still_resolves(): void
    {
        $this->actingAs($this->admin())->get('/endorsement/picu')->assertOk();
    }


    public function test_show_renders_the_editable_sheet_for_a_unit_and_date(): void
    {
        $row = $this->handover('PICU', '2026-07-10', ['disease' => '<p>Sepsis</p>']);

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU/2026-07-10')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Endorsement/Sheet')
                ->where('unit.code', 'PICU')
                ->where('date', '2026-07-10')
                ->has('rows', 1)
                ->where('rows.0.id', $row->id)
            );
    }

    /**
     * Task 6 / Decision A — the sheet header's Hijri date and its "Start next day" /
     * "Previous day" navigation used to be computed in the browser (Sheet.vue's deleted
     * `nextDate`, which built a `new Date()` from the route param). Both adjacent dates now
     * arrive as server-formatted props so the client does no date arithmetic at all.
     */
    public function test_show_sends_the_hijri_date_and_the_adjacent_calendar_days(): void
    {
        $this->handover('PICU', '2026-07-10');

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU/2026-07-10')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Endorsement/Sheet')
                ->where('date', '2026-07-10')
                ->where('date_hijri', fn (mixed $v): bool => is_string($v) && $v !== '')
                ->where('next_date', '2026-07-11')
                ->where('previous_date', '2026-07-09')
            );
    }

    /** A month boundary is exactly the case client-side `Date` arithmetic used to get wrong. */
    public function test_the_adjacent_calendar_days_cross_a_month_boundary_correctly(): void
    {
        $this->handover('PICU', '2026-07-31');

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU/2026-07-31')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('next_date', '2026-08-01')
                ->where('previous_date', '2026-07-30')
            );
    }

    public function test_new_day_carries_the_prior_census_forward_including_the_narrative(): void
    {
        $this->handover('PICU', '2026-07-10', [
            'bed' => '3',
            'mrn' => 'CARRY-1',
            'patient_name' => 'Carried Child',
            'disease' => '<p>Bronchiolitis</p>',
            'details' => '<b>on HFNC</b>',
            'plan' => 'wean support',
            'nevent' => 'desaturation overnight',
        ]);

        $this->actingAs($this->editor())
            ->post('/endorsement/PICU/new-day', ['date' => '2026-07-11'])
            ->assertRedirect();

        $unitId = Unit::where('code', 'PICU')->value('id');
        $carried = Handover::where('unit_id', $unitId)->whereDate('handover_date', '2026-07-11')->get();

        $this->assertCount(1, $carried);
        $new = $carried->first();

        // Census carried forward.
        $this->assertSame('3', $new->bed);
        $this->assertSame('CARRY-1', $new->mrn);
        $this->assertSame('Carried Child', $new->patient_name);
        $this->assertStringContainsString('Bronchiolitis', (string) $new->disease);
        $this->assertStringContainsString('on HFNC', (string) $new->details);
        $this->assertStringContainsString('wean support', (string) $new->plan);

        // Ruling 1 — the "To be followed" narrative carries VERBATIM (legacy parity).
        $this->assertStringContainsString('desaturation overnight', (string) $new->nevent);
    }

    public function test_new_day_requires_the_edit_capability(): void
    {
        $nurse = User::factory()->create(['position' => 1]);

        $this->actingAs($nurse)->post('/endorsement/PICU/new-day', ['date' => '2026-07-11'])->assertForbidden();
    }

    public function test_store_row_sanitizes_rich_text_on_write(): void
    {
        $this->actingAs($this->editor())
            ->post('/endorsement/PICU/2026-07-10/rows', [
                'bed' => '5',
                'mrn' => 'XSS-1',
                'patient_name' => 'Child',
                'details' => '<b>stable</b><script>alert(1)</script>',
            ])
            ->assertRedirect();

        $row = Handover::latest('id')->get()->firstWhere('mrn', 'XSS-1');

        // Stored-XSS defense: the script is stripped, the allow-listed markup preserved.
        $this->assertStringNotContainsString('<script', (string) $row->details);
        $this->assertStringContainsString('<b>stable</b>', (string) $row->details);

        // A PHI-free audit row records the write (id only, no names/MRNs).
        $audit = AuditLog::where('action', 'endorsement_row_create')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('XSS-1', (string) $audit->detail);
    }

    /**
     * PICU's UnitProfile has no extra identity columns, so the neonatal `dob` and the ward
     * `age` / `ward_unit` fields are dropped on write for PICU rows (spec §3).
     */
    public function test_picu_drops_the_other_units_identity_fields_on_write(): void
    {
        $this->actingAs($this->editor())
            ->post('/endorsement/PICU/2026-07-10/rows', [
                'bed' => '1',
                'mrn' => 'PICU-NF-1',
                'patient_name' => 'PICU Child',
                'dob' => '2026-07-01 03:20:00',
                'age' => '4 years',
                'ward_unit' => 'Green',
            ])
            ->assertRedirect();

        $row = Handover::latest('id')->get()->firstWhere('mrn', 'PICU-NF-1');
        $this->assertNull($row->dob, 'the neonatal dob field is no longer part of the surface');
        $this->assertNull($row->age, 'the ward age field is no longer part of the surface');
        $this->assertNull($row->ward_unit, 'the ward sub-unit field is no longer part of the surface');
    }

    /** G1 — the sheet no longer negotiates unit-specific columns with the client. */
    public function test_the_sheet_no_longer_sends_unit_specific_column_flags(): void
    {
        $this->handover('PICU', '2026-07-10');

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU/2026-07-10')
            ->assertInertia(fn (Assert $page) => $page->missing('showDob')->missing('showAge'));

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU/2026-07-10/print')
            ->assertInertia(fn (Assert $page) => $page->missing('showDob')->missing('showAge'));
    }

    public function test_update_row_edits_an_existing_handover_row(): void
    {
        $row = $this->handover('PICU', '2026-07-10', ['plan' => 'original']);

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['plan' => 'updated plan'])
            ->assertRedirect();

        $this->assertStringContainsString('updated plan', (string) $row->fresh()->plan);
    }

    public function test_delete_row_removes_a_handover_row(): void
    {
        $row = $this->handover('PICU', '2026-07-10');

        $this->actingAs($this->editor())
            ->delete('/endorsement/rows/'.$row->id)
            ->assertRedirect();

        $this->assertNull(Handover::find($row->id));
    }

    public function test_a_nurse_cannot_edit_rows(): void
    {
        $nurse = User::factory()->create(['position' => 1]);
        $row = $this->handover('PICU', '2026-07-10');

        $this->actingAs($nurse)->patch('/endorsement/rows/'.$row->id, ['plan' => 'x'])->assertForbidden();
        $this->actingAs($nurse)->delete('/endorsement/rows/'.$row->id)->assertForbidden();
        $this->actingAs($nurse)->post('/endorsement/PICU/2026-07-10/rows', ['bed' => '1'])->assertForbidden();
    }

    /**
     * G3 — the round-trip contract between the rich-text TOOLBAR and the sanitizer, and the reason
     * `RichTextEditor` brackets `styleWithCSS` PER COMMAND rather than globally as legacy did.
     *
     * The markup below is not hand-written: it is the VERBATIM output of Chrome's `execCommand` for
     * each toolbar button, captured from a real browser running the component's own `exec()`. That
     * matters because a toolbar whose output the sanitizer strips is worse than no toolbar — staff
     * format text, see it apply, and lose it on save with no error. Every form here must survive
     * unchanged; `<script>` must still be stripped, since the allow-list is NOT being widened.
     */
    public function test_real_browser_toolbar_markup_round_trips_while_script_is_stripped(): void
    {
        $row = $this->handover('PICU', '2026-07-10');

        // Captured from Chrome: bold/italic/underline/lists with styleWithCSS OFF (tag output),
        // the two colour commands with it ON (span[style] output).
        $formatted = '<b>bold</b><i>ital</i><u>under</u>'
            .'<ul><li>one</li><li>two</li></ul>'
            .'<ol><li>first</li></ol>'
            .'<span style="color: rgb(179, 38, 30);">red</span>'
            .'<span style="background-color: rgb(255, 255, 0);">flag</span>'
            .'<script>alert(1)</script>';

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['details' => $formatted])
            ->assertRedirect();

        $saved = (string) $row->fresh()->details;

        // Bold / italic / underline survive as TAGS. These are the assertions that fail if anyone
        // reinstates legacy's global styleWithCSS: the CSS forms of italic (`font-style`) and
        // underline (`text-decoration-line`) are not allow-listed and collapse to a bare <span>.
        $this->assertStringContainsString('<b>bold</b>', $saved);
        $this->assertStringContainsString('<i>ital</i>', $saved);
        $this->assertStringContainsString('<u>under</u>', $saved);

        // Lists survive intact.
        $this->assertStringContainsString('<ul><li>one</li><li>two</li></ul>', $saved);
        $this->assertStringContainsString('<ol><li>first</li></ol>', $saved);

        // Both colours survive (HTMLPurifier only strips the whitespace from the declaration).
        $compact = str_replace(' ', '', $saved);
        $this->assertStringContainsString('color:rgb(179,38,30)', $compact);
        $this->assertStringContainsString('background-color:rgb(255,255,0)', $compact);

        // Sanitization is NOT weakened by any of the above.
        $this->assertStringNotContainsString('<script', $saved);
        $this->assertStringNotContainsString('alert(1)', $saved);
    }

    /**
     * The negative half of the contract, and the regression guard for the latent legacy bug: the
     * CSS forms of italic and underline that legacy's global `styleWithCSS` produced ARE stripped.
     * This documents WHY the toolbar must not emit them — if this ever starts passing through, the
     * allow-list was widened and the per-command bracketing can be revisited.
     */
    public function test_the_css_forms_of_italic_and_underline_are_not_allow_listed(): void
    {
        $row = $this->handover('PICU', '2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, [
                'details' => '<span style="font-style: italic;">i</span>'
                    .'<span style="text-decoration-line: underline;">u</span>',
            ])
            ->assertRedirect();

        $saved = (string) $row->fresh()->details;

        $this->assertStringNotContainsString('font-style', $saved);
        $this->assertStringNotContainsString('text-decoration-line', $saved);
    }

    /**
     * G3 — colouring text that is ALREADY bold/underlined. Verified in Chrome: the toolbar emits
     * `<b style="color:…">` / `<u style="color:…">` rather than wrapping a span, so the sanitizer
     * must accept `[style]` on those tags or the colour is silently lost on save. "Bold it, then
     * flag it red" is a routine escalation marking on a handover sheet.
     */
    public function test_a_colour_applied_over_bold_or_underline_survives_the_save(): void
    {
        $row = $this->handover('PICU', '2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, [
                'details' => '<b style="color: rgb(179, 38, 30);">escalate</b>'
                    .'<u style="color: rgb(179, 38, 30);">now</u>'
                    .'<ul><li style="background-color: rgb(255, 255, 0);">flagged</li></ul>',
            ])
            ->assertRedirect();

        $compact = str_replace(' ', '', (string) $row->fresh()->details);

        $this->assertStringContainsString('<bstyle="color:rgb(179,38,30);">escalate</b>', $compact);
        $this->assertStringContainsString('<ustyle="color:rgb(179,38,30);">now</u>', $compact);
        $this->assertStringContainsString('background-color:rgb(255,255,0)', $compact);
    }

    /**
     * The guard on the above: allowing `[style]` on more tags must NOT widen what can go INSIDE a
     * style attribute. The CSS property allow-list is unchanged, so every injection vector and every
     * layout/behaviour property is still stripped — on the newly-style-bearing tags too.
     */
    public function test_widening_style_to_formatting_tags_did_not_weaken_sanitization(): void
    {
        $row = $this->handover('PICU', '2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, [
                'details' => '<b style="color:expression(alert(1))">a</b>'
                    .'<b style="behavior:url(evil.htc)">b</b>'
                    .'<u style="background-image:url(javascript:alert(1))">c</u>'
                    .'<b style="position:fixed;top:0">d</b>'
                    .'<b style="font-style:italic">e</b>'
                    .'<b onclick="alert(1)">f</b>'
                    .'<script>alert(1)</script>',
            ])
            ->assertRedirect();

        $saved = (string) $row->fresh()->details;

        foreach (['expression', 'behavior', 'javascript:', 'position', 'font-style', 'onclick', '<script'] as $vector) {
            $this->assertStringNotContainsString($vector, $saved, $vector.' must still be stripped');
        }

        // The text content itself is retained — only the dangerous declarations are removed.
        $this->assertStringContainsString('<b>a</b>', $saved);
    }

    /** G3 — the legacy day index filtered by start/end date (`PICU-Endorsement.php:120-131`). */
    public function test_the_day_index_can_be_filtered_by_a_date_range(): void
    {
        $this->handover('PICU', '2026-07-01');
        $this->handover('PICU', '2026-07-10');
        $this->handover('PICU', '2026-07-20');

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU?from=2026-07-05&to=2026-07-15')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('dates', 1)
                ->where('dates.0.date', '2026-07-10')
                ->where('filters.from', '2026-07-05')
                ->where('filters.to', '2026-07-15')
            );

        // An open-ended bound filters from that date onward.
        $this->actingAs($this->admin())
            ->get('/endorsement/PICU?from=2026-07-10')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('dates', 2));

        // No filter = every day (the unfiltered index is unchanged).
        $this->actingAs($this->admin())
            ->get('/endorsement/PICU')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('dates', 3));
    }

    /**
     * G3 — back-filling a missed night. The legacy date-picker modal
     * (`PICU-Endorsement.php:172-199`) posted an arbitrary date to the new-day action. Creating a
     * day must produce a REAL day: when there is no prior census to carry, the day is seeded with a
     * single blank row so it exists on the index and is editable — otherwise "create" is a no-op.
     */
    public function test_a_sheet_can_be_created_for_an_explicit_past_date(): void
    {
        $unitId = Unit::where('code', 'PICU')->value('id');

        // No prior day at all: creating one must still yield a usable, listed day.
        $this->actingAs($this->editor())
            ->post('/endorsement/PICU/new-day', ['date' => '2026-07-02'])
            ->assertRedirect('/endorsement/PICU/2026-07-02');

        $this->assertTrue(
            Handover::where('unit_id', $unitId)->whereDate('handover_date', '2026-07-02')->exists(),
            'creating a sheet for a past date must not be a no-op',
        );

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU')
            ->assertInertia(fn (Assert $page) => $page->where('dates.0.date', '2026-07-02'));
    }

    /**
     * G3 — legacy row ordering (`picu-endorsement-patients.php:240-255`): beds ascending with BLANK
     * beds forced LAST. Plain `orderBy('bed')` sorted blanks first and compared beds as strings, so
     * bed 10 preceded bed 2 and a freshly-added blank row jumped to the head of the sheet.
     */
    public function test_rows_sort_beds_numerically_with_blank_beds_last(): void
    {
        $this->handover('PICU', '2026-07-10', ['bed' => '10', 'mrn' => 'B-10']);
        $this->handover('PICU', '2026-07-10', ['bed' => '', 'mrn' => 'B-BLANK']);
        $this->handover('PICU', '2026-07-10', ['bed' => '2', 'mrn' => 'B-2']);
        $this->handover('PICU', '2026-07-10', ['bed' => null, 'mrn' => 'B-NULL']);
        $this->handover('PICU', '2026-07-10', ['bed' => '1', 'mrn' => 'B-1']);

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU/2026-07-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.mrn', 'B-1')
                ->where('rows.1.mrn', 'B-2')
                // Numeric-aware: bed 10 sorts AFTER bed 2, not before it.
                ->where('rows.2.mrn', 'B-10')
                // Blank / NULL beds are pushed to the bottom.
                ->where('rows.3.bed', '')
                ->where('rows.4.bed', '')
            );
    }

    /**
     * Ruling 1 — nevent carries forward (legacy parity), and the flash SAYS so, so the
     * behaviour is explicit to staff at the moment it happens.
     */
    public function test_new_day_states_that_the_narrative_carried_over(): void
    {
        $this->handover('PICU', '2026-07-10', ['nevent' => 'desaturation overnight']);

        $this->actingAs($this->editor())
            ->post('/endorsement/PICU/new-day', ['date' => '2026-07-11'])
            ->assertSessionHas('status', fn (string $s): bool => str_contains(strtolower($s), 'to be followed'));
    }

    /** Idempotent per target date: a second new-day call duplicates nothing. */
    public function test_new_day_is_idempotent_per_target_date(): void
    {
        $this->handover('PICU', '2026-07-10', ['mrn' => 'IDEM-1']);

        $editor = $this->editor();
        $this->actingAs($editor)->post('/endorsement/PICU/new-day', ['date' => '2026-07-11'])->assertRedirect();
        $this->actingAs($editor)->post('/endorsement/PICU/new-day', ['date' => '2026-07-11'])->assertRedirect();

        $unitId = Unit::where('code', 'PICU')->value('id');
        $this->assertSame(1, Handover::where('unit_id', $unitId)->whereDate('handover_date', '2026-07-11')->count());
    }

    /**
     * Ruling 2 — THE CARRY DIALOG. When the most recent prior sheet is OLDER than
     * yesterday, nothing is created until the user explicitly chooses; the prompt carries
     * the last endorsement date so the dialog can show it.
     */
    public function test_a_gap_prompts_for_a_carry_choice_and_creates_nothing(): void
    {
        $this->handover('PICU', '2026-07-05', ['mrn' => 'GAP-1']);

        $this->actingAs($this->editor())
            ->from('/endorsement/PICU')
            ->post('/endorsement/PICU/new-day', ['date' => '2026-07-11'])
            ->assertRedirect('/endorsement/PICU')
            ->assertSessionHas('carry_prompt', fn (array $p): bool => $p['last_date'] === '2026-07-05' && $p['date'] === '2026-07-11');

        $unitId = Unit::where('code', 'PICU')->value('id');
        $this->assertSame(0, Handover::where('unit_id', $unitId)->whereDate('handover_date', '2026-07-11')->count());
    }

    public function test_a_gap_with_an_explicit_carry_choice_copies_the_last_census(): void
    {
        $this->handover('PICU', '2026-07-05', ['mrn' => 'GAP-2', 'nevent' => 'old follow-up']);

        $this->actingAs($this->editor())
            ->post('/endorsement/PICU/new-day', ['date' => '2026-07-11', 'carry_choice' => 'carry'])
            ->assertRedirect();

        $unitId = Unit::where('code', 'PICU')->value('id');
        $new = Handover::where('unit_id', $unitId)->whereDate('handover_date', '2026-07-11')->firstOrFail();
        $this->assertSame('GAP-2', $new->mrn);
        $this->assertStringContainsString('old follow-up', (string) $new->nevent);
    }

    public function test_a_gap_with_a_blank_choice_starts_an_empty_sheet(): void
    {
        $this->handover('PICU', '2026-07-05', ['mrn' => 'GAP-3']);

        $this->actingAs($this->editor())
            ->post('/endorsement/PICU/new-day', ['date' => '2026-07-11', 'carry_choice' => 'blank'])
            ->assertRedirect();

        $unitId = Unit::where('code', 'PICU')->value('id');
        $rows = Handover::where('unit_id', $unitId)->whereDate('handover_date', '2026-07-11')->get();
        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->mrn);
    }

    /** With no prior day at all, the target is seeded with one blank editable row. */
    public function test_new_day_with_no_history_seeds_one_blank_row(): void
    {
        $this->actingAs($this->editor())
            ->post('/endorsement/PICU/new-day', ['date' => '2026-07-11'])
            ->assertRedirect();

        $unitId = Unit::where('code', 'PICU')->value('id');
        $this->assertSame(1, Handover::where('unit_id', $unitId)->whereDate('handover_date', '2026-07-11')->count());
    }

    /** The new-day audit row records the carried count, ids only. */
    public function test_new_day_audits_the_carried_count(): void
    {
        $this->handover('PICU', '2026-07-10', ['mrn' => 'AUD-1', 'patient_name' => 'Audit Child']);

        $this->actingAs($this->editor())
            ->post('/endorsement/PICU/new-day', ['date' => '2026-07-11'])
            ->assertRedirect();

        $audit = AuditLog::where('action', 'endorsement_new_day')->latest('id')->firstOrFail();
        $this->assertStringContainsString('carried=1', (string) $audit->detail);
        $this->assertStringNotContainsString('AUD-1', (string) $audit->detail);
        $this->assertStringNotContainsString('Audit Child', (string) $audit->detail);
    }

    /** A payload produced under a key this application no longer has (WrongKeyProtectionTest's helper). */
    private function foreignCiphertext(string $plaintext = '2020-01-01 00:00:00'): string
    {
        $foreign = new \Illuminate\Encryption\Encrypter(random_bytes(32), 'aes-256-cbc');

        return $foreign->encryptString($plaintext);
    }

    /**
     * Fix 2 regression coverage: closing EncryptedDateTime's wrong-key gap widened
     * `Handover::dob`'s get() to Carbon|string|null. A row whose dob is foreign ciphertext
     * must still render the sheet 200 (EndorsementController::rowsFor()) and must not block
     * New Day for the rest of the unit (EndorsementController::newDay()'s carry-forward).
     * Bypasses the Eloquent cast on insert (DB::table(), same as
     * PhiEncryptionAtRestTest::test_a_plaintext_legacy_row_is_still_readable()) because the
     * cast's own set() would reject writing unparsable text into a date column.
     */
    public function test_a_wrong_key_dob_renders_the_sheet_instead_of_500ing(): void
    {
        $unitId = Unit::where('code', 'PICU')->value('id');

        DB::table('handovers')->insert([
            'unit_id' => $unitId,
            'handover_date' => '2026-07-10',
            'bed' => '5',
            'mrn' => 'WRONGKEY-1',
            'patient_name' => 'Unreadable Dob Child',
            'dob' => $this->foreignCiphertext(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU/2026-07-10')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Endorsement/Sheet')
                ->has('rows', 1)
                ->where('rows.0.mrn', 'WRONGKEY-1')
                ->where('rows.0.dob', fn (?string $dob) => $dob !== null
                    && str_contains(strtolower($dob), 'unreadable'))
            );
    }

    public function test_a_wrong_key_dob_does_not_block_new_day_for_the_rest_of_the_unit(): void
    {
        $unitId = Unit::where('code', 'PICU')->value('id');

        DB::table('handovers')->insert([
            'unit_id' => $unitId,
            'handover_date' => '2026-07-10',
            'bed' => '5',
            'mrn' => 'WRONGKEY-2',
            'patient_name' => 'Unreadable Dob Child',
            'dob' => $this->foreignCiphertext(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // A normal sibling row on the same source day — proves the whole unit is not blocked.
        $this->handover('PICU', '2026-07-10', ['bed' => '6', 'mrn' => 'NORMAL-1']);

        $this->actingAs($this->editor())
            ->post('/endorsement/PICU/new-day', ['date' => '2026-07-11'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $carried = Handover::where('unit_id', $unitId)->whereDate('handover_date', '2026-07-11')->get();
        $this->assertCount(2, $carried);

        $wrongKeyRow = $carried->firstWhere('mrn', 'WRONGKEY-2');
        $this->assertNotNull($wrongKeyRow);
        // The foreign-ciphertext marker is dropped rather than passed through — carry-forward
        // creates a NEW row, so the source row's own ciphertext was never at risk either way.
        $this->assertNull($wrongKeyRow->dob);

        $normalRow = $carried->firstWhere('mrn', 'NORMAL-1');
        $this->assertNotNull($normalRow);
    }

    public function test_print_view_renders_for_a_unit_and_date(): void
    {
        $row = $this->handover('PICU', '2026-07-10');

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU/2026-07-10/print')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Endorsement/Print')
                ->where('unit.code', 'PICU')
                ->where('date', '2026-07-10')
                ->has('rows', 1)
                ->where('rows.0.id', $row->id)
            );
    }

    /**
     * P0b — finding 1, the highest-risk bug in the plan. Autosave PATCHes one field at a
     * time, but the whole custom-field map is ONE encrypted column. Assigning the submitted
     * subset directly would replace the map and silently wipe every sibling key. Without a
     * merge-on-write, the second PATCH here would leave `weight` gone.
     */
    public function test_saving_one_custom_field_does_not_wipe_the_others(): void
    {
        $this->defineField('PICU', 'weight');
        $this->defineField('PICU', 'allergy');

        $row = $this->handover('PICU', '2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['extra_fields' => ['weight' => '3.2']])
            ->assertRedirect();

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['extra_fields' => ['allergy' => 'penicillin']])
            ->assertRedirect();

        $stored = $row->fresh()->extra_fields;
        $this->assertSame('3.2', $stored['weight'] ?? null, 'the first field must survive the second PATCH');
        $this->assertSame('penicillin', $stored['allergy'] ?? null);
    }

    public function test_a_required_custom_field_rejects_an_empty_value(): void
    {
        $this->defineField('PICU', 'weight', ['required' => true]);
        $row = $this->handover('PICU', '2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['extra_fields' => ['weight' => '']])
            ->assertSessionHasErrors('extra_fields.weight');
    }

    public function test_a_select_custom_field_rejects_a_value_outside_its_options(): void
    {
        $this->defineField('PICU', 'feed_type', ['type' => 'select', 'options' => ['breast', 'formula']]);
        $row = $this->handover('PICU', '2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['extra_fields' => ['feed_type' => 'unknown']])
            ->assertSessionHasErrors('extra_fields.feed_type');

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['extra_fields' => ['feed_type' => 'formula']])
            ->assertSessionHasNoErrors();
    }

    public function test_a_date_custom_field_rejects_a_non_date(): void
    {
        $this->defineField('PICU', 'admitted_on', ['type' => 'date']);
        $row = $this->handover('PICU', '2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['extra_fields' => ['admitted_on' => 'not-a-date']])
            ->assertSessionHasErrors('extra_fields.admitted_on');
    }

    /**
     * Namespacing under extra_fields.* (only a rule per KNOWN definition) is what keeps this
     * safe: a key with no active definition never has a validation rule, so Laravel's
     * excludeUnvalidatedArrayKeys drops it from the validated data before it ever reaches
     * the merge — it is never persisted.
     */
    public function test_an_unknown_custom_field_key_is_ignored_rather_than_stored(): void
    {
        $this->defineField('PICU', 'weight');
        $row = $this->handover('PICU', '2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['extra_fields' => ['weight' => '3.2', 'hacked' => 'x']])
            ->assertRedirect();

        $stored = $row->fresh()->extra_fields;
        $this->assertSame('3.2', $stored['weight'] ?? null);
        $this->assertArrayNotHasKey('hacked', $stored);
    }

    /**
     * Retiring a definition must not delete the values already stored under its key
     * (UnitFieldDefinition's own docblock) — it only stops the key from being rendered or
     * accepting new writes. The merge-on-write is what actually protects this: the retired
     * key never appears in a later PATCH's validated data, so it can only survive if the
     * merge preserves untouched keys rather than replacing the map.
     */
    public function test_a_value_for_a_retired_definition_survives_a_later_save(): void
    {
        $weight = $this->defineField('PICU', 'weight');
        $this->defineField('PICU', 'allergy');
        $row = $this->handover('PICU', '2026-07-10');

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['extra_fields' => ['weight' => '3.2']])
            ->assertRedirect();

        $weight->update(['active' => false]);

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['extra_fields' => ['allergy' => 'penicillin']])
            ->assertRedirect();

        $stored = $row->fresh()->extra_fields;
        $this->assertSame('3.2', $stored['weight'] ?? null, 'retiring a definition must not delete its stored value');
        $this->assertSame('penicillin', $stored['allergy'] ?? null);
    }

    /**
     * A definition row whose `type` did not come through UnitFieldDefinition's model guard
     * (e.g. inserted by SQL) must still be validated as `text` rather than silently getting
     * no rule at all — an unbounded, unvalidated string is worse than treating it as text.
     */
    public function test_an_unknown_definition_type_is_validated_as_text(): void
    {
        $unitId = Unit::where('code', 'PICU')->value('id');

        DB::table('unit_field_definitions')->insert([
            'unit_id' => $unitId,
            'key' => 'mystery',
            'label' => 'Mystery Field',
            'type' => 'number', // not text|date|select — bypasses the model's saving guard
            'required' => false,
            'display_order' => 1000,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = $this->handover('PICU', '2026-07-10');

        // Over the text fallback's max:500 — proves a rule actually ran, not just "no error".
        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['extra_fields' => ['mystery' => str_repeat('x', 501)]])
            ->assertSessionHasErrors('extra_fields.mystery');

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['extra_fields' => ['mystery' => 'ok value']])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('ok value', $row->fresh()->extra_fields['mystery'] ?? null);
    }

    public function test_unit_payload_exposes_active_field_definitions_for_generic_rendering(): void
    {
        $this->defineField('PICU', 'weight', ['label' => 'Weight (kg)', 'type' => 'text']);
        $this->defineField('PICU', 'retired', ['active' => false]);

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU/2026-07-10')
            ->assertInertia(fn (Assert $page) => $page
                ->has('unit.profile.field_definitions', 1)
                ->where('unit.profile.field_definitions.0.key', 'weight')
                ->where('unit.profile.field_definitions.0.label', 'Weight (kg)')
                ->where('unit.profile.field_definitions.0.type', 'text')
            );
    }

    public function test_rows_carry_the_saved_custom_field_values(): void
    {
        $this->defineField('PICU', 'weight');
        $row = $this->handover('PICU', '2026-07-10');
        $row->update(['extra_fields' => ['weight' => '3.2']]);

        $this->actingAs($this->admin())
            ->get('/endorsement/PICU/2026-07-10')
            ->assertInertia(fn (Assert $page) => $page->where('rows.0.extra_fields.weight', '3.2'));
    }

    /**
     * rowsFor() casts to (object) so the JSON shape is a stable `{}` rather than flipping
     * between `{}` and `[]`, which would break client code that assumes an object.
     */
    public function test_extra_fields_serializes_as_an_object_even_when_empty(): void
    {
        $this->handover('PICU', '2026-07-10');

        $response = $this->actingAs($this->admin())->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new \App\Http\Middleware\HandleInertiaRequests)->version(request()),
            'X-Requested-With' => 'XMLHttpRequest',
        ])->get('/endorsement/PICU/2026-07-10');

        $response->assertOk();
        $this->assertStringContainsString('"extra_fields":{}', $response->getContent());
    }

    /**
     * Step 7 — `assertNotOverwritingForeignCiphertext()` throws a bare RuntimeException, and
     * nothing upstream of updateRow() catches it, so an autosave against a wrong-key row
     * would otherwise 500 with the reason only in the log. The project rule is that autosave
     * reflects the server response, so the controller must convert it to a ValidationException
     * — and the foreign ciphertext must survive the refused write untouched, since refusing is
     * what keeps it recoverable once the correct APP_KEY is restored.
     */
    public function test_a_wrong_key_extra_fields_refusal_is_a_validation_error_not_a_500(): void
    {
        $this->defineField('PICU', 'weight');
        $unitId = Unit::where('code', 'PICU')->value('id');

        $foreignCiphertext = $this->foreignCiphertext('{"weight":"3.2"}');

        DB::table('handovers')->insert([
            'unit_id' => $unitId,
            'handover_date' => '2026-07-10',
            'bed' => '5',
            'mrn' => 'WRONGKEY-EXTRA',
            'patient_name' => 'Unreadable Extra Fields Child',
            'extra_fields' => $foreignCiphertext,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $row = Handover::where('mrn', 'WRONGKEY-EXTRA')->firstOrFail();

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['extra_fields' => ['weight' => '4.0']])
            ->assertSessionHasErrors('extra_fields');

        $raw = DB::table('handovers')->where('id', $row->id)->value('extra_fields');
        $this->assertSame($foreignCiphertext, $raw, 'a refused write must leave the recoverable ciphertext untouched');
    }
}
