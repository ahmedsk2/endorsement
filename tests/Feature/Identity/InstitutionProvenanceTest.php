<?php

namespace Tests\Feature\Identity;

use App\Models\Handover;
use App\Models\Institution;
use App\Models\Invitation;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D11 keeps `institution_id` as in-instance grouping and provenance (design §3.4) — never a
 * filter (see test_no_query_filters_on_institution_id below). Finding 9: nothing in app/ ever
 * set it, so it was NULL on every application-written row while `LegacyImport` stamped a real id
 * on every imported row — non-null history, null present, worse than uniformly null. Task 4
 * gives the column its one writer: the bootstrap admin, from which every other site already
 * copies (EndorsementController::339/601/632/658, Invitation::67, InvitationAcceptController::
 * 104/161, UserManagementController::152/177).
 */
class InstitutionProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    private function createAdminViaCommand(string $username = 'ahmed'): User
    {
        $this->artisan('user:create-admin')
            ->expectsQuestion('Username (used to sign in)', $username)
            ->expectsQuestion('Full name (shown on printed handovers)', 'Dr Ahmed Administrator')
            ->expectsQuestion('Email address', $username.'@example.org')
            ->expectsQuestion('Password', 'Str0ng-pass!x')
            ->expectsQuestion('Confirm password', 'Str0ng-pass!x')
            ->assertExitCode(0);

        return User::where('member_name', $username)->firstOrFail();
    }

    public function test_the_bootstrap_admin_is_attached_to_the_single_institution(): void
    {
        $this->seed(ReferenceSeeder::class);
        $institution = Institution::firstOrFail();

        $admin = $this->createAdminViaCommand();

        $this->assertSame($institution->id, $admin->institution_id);
        $this->assertSame($institution->id, $admin->person->institution_id);
    }

    public function test_a_clinical_row_created_by_the_admin_carries_the_institution_id(): void
    {
        $this->seed(ReferenceSeeder::class);
        $institution = Institution::firstOrFail();
        $admin = $this->createAdminViaCommand();
        // RequireSetup would otherwise redirect the freshly bootstrapped admin to /setup —
        // unrelated to this task, so bypass it directly rather than walking the setup wizard.
        $admin->forceFill(['setup_completed_at' => now()])->save();

        $this->actingAs($admin)
            ->post('/endorsement/PICU/2026-07-10/rows', [
                'bed' => '1',
                'mrn' => 'M-1',
                'patient_name' => 'Test Child',
            ])
            ->assertRedirect();

        // `mrn` is encrypted at rest (App\Casts\EncryptedString): a `where('mrn', ...)` clause
        // compares against ciphertext and can never match a plaintext value, so fetch and
        // filter in PHP after the cast decrypts it — the same pattern EndorsementTest uses.
        $row = Handover::latest('id')->get()->firstWhere('mrn', 'M-1');
        $this->assertNotNull($row);
        $this->assertSame($institution->id, $row->institution_id);
    }

    public function test_an_invitation_issued_by_the_admin_and_the_person_it_claims_carry_the_institution_id(): void
    {
        $this->seed(ReferenceSeeder::class);
        $institution = Institution::firstOrFail();
        $admin = $this->createAdminViaCommand();
        // RequireSetup would otherwise redirect the freshly bootstrapped admin to /setup —
        // unrelated to this task, so bypass it directly rather than walking the setup wizard.
        $admin->forceFill(['setup_completed_at' => now()])->save();

        $this->actingAs($admin)
            ->post('/admin/invitations', ['member_email' => 'invitee@example.org', 'position' => 4])
            ->assertSessionHasNoErrors();

        $invitation = Invitation::firstOrFail();
        $this->assertSame($institution->id, $invitation->institution_id);

        // The plaintext token is never persisted, so redeem through a fresh invitation issued
        // directly against the same (already-linked) person — same discipline as
        // ClaimLifecycleTest::test_redemption_claims_the_existing_person.
        $person = Person::findOrFail($invitation->person_id);
        [, $token] = Invitation::issue('invitee@example.org', 4, $admin, $person);

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Invitee',
            'member_name' => 'invitee',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login')->assertSessionHasNoErrors();

        $claimed = User::where('member_name', 'invitee')->firstOrFail();
        $this->assertSame($institution->id, $claimed->institution_id);
        $this->assertSame($institution->id, $claimed->person->institution_id);
    }

    /**
     * The only door into the system must never fail because a fresh instance has not been
     * seeded yet.
     */
    public function test_with_zero_institutions_create_admin_still_succeeds_and_leaves_the_column_null(): void
    {
        // No ReferenceSeeder run — zero institutions exist.
        $this->artisan('user:create-admin')
            ->expectsQuestion('Username (used to sign in)', 'ahmed')
            ->expectsQuestion('Full name (shown on printed handovers)', 'Dr Ahmed Administrator')
            ->expectsQuestion('Email address', 'ahmed@example.org')
            ->expectsQuestion('Password', 'Str0ng-pass!x')
            ->expectsQuestion('Confirm password', 'Str0ng-pass!x')
            ->expectsOutputToContain('No single active institution found')
            ->assertExitCode(0);

        $admin = User::where('member_name', 'ahmed')->firstOrFail();

        $this->assertNull($admin->institution_id);
        $this->assertNull($admin->person->institution_id);
    }

    /**
     * The one legitimate `whereNull('institution_id')` site: the backfill migration fills the
     * column on rows an upgrading instance already has (D11/finding 9 — nothing in app/ ever
     * set it before Task 4). Filtering FOR the column being null in order to fill it is not the
     * fail-open tenancy filter this guard exists to catch — there is no other institution to
     * leak across, and the predicate never runs against a second customer's database (D11: one
     * database per customer). Named explicitly, with `test_the_backfill_allow_list_is_not_stale`
     * below keeping it honest the other way.
     */
    private const WHERE_NULL_ALLOW_LIST = [
        'database/migrations/2026_08_11_120001_backfill_institution_on_identity_rows.php',
    ];

    /**
     * The guard that keeps this honest: no clinical query filters on `institution_id`. D11
     * rejected row-level tenancy because it fails open — one missing scope and one customer
     * reads another's PHI. Assert it as a source-level fact so a future session cannot quietly
     * turn provenance into a fail-open security boundary.
     *
     * Scope: `app/`, `database/` (migrations are a live query surface too — `index()`/
     * `unique()` on `institution_id` is exactly as toothless a boundary as a `where()`, and P1a
     * Task 4 and Task 7 each nearly shipped one; both were caught by luck, not by this test, per
     * the reviewer who found this gap) and `routes/` (closures can query directly).
     *
     * Two independent patterns:
     *  - QUERY_PATTERN: any `where`-family call whose first argument is `'institution_id'` —
     *    case-insensitive so `orWhere(`/`firstWhere(` (capital W) are caught, not just a
     *    lowercase `where` prefix, and covering both the plain-argument form
     *    (`where('institution_id', ...)`) and the array form (`where(['institution_id' => ...])`).
     *  - KEY_PATTERN: any migration `index([...])`/`unique([...])` whose column list includes
     *    `'institution_id'` — a composite key is a query boundary in waiting; D11 already
     *    documents (`periods`/`holidays` migrations) that no compound unique index in this
     *    schema may include it, for the same reason.
     */
    public function test_no_query_filters_on_institution_id(): void
    {
        $queryPattern = '/where\w*\(\s*(\[[^\]]*)?[\'"]institution_id/i';
        $keyPattern = '/(index|unique)\(\s*\[[^\]]*[\'"]institution_id/';

        $hits = [];

        foreach ([app_path(), base_path('database'), base_path('routes')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();
                $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
                $src = (string) file_get_contents($path);

                if (preg_match($queryPattern, $src) && ! in_array($relative, self::WHERE_NULL_ALLOW_LIST, true)) {
                    $hits[] = "{$relative} (query filter)";
                }

                if (preg_match($keyPattern, $src)) {
                    $hits[] = "{$relative} (index/unique key)";
                }
            }
        }

        $this->assertSame([], $hits,
            'D11: the isolation boundary is the DATABASE, not the row. Row-level tenancy fails '
            .'open — one missing scope and one customer reads another\'s PHI. institution_id is '
            .'provenance and in-instance grouping ONLY — never a query filter and never part of '
            .'an index or unique key (a composite key including it is exactly as toothless a '
            .'boundary as a where() clause). If you need to scope a query by customer, you need '
            .'a second deployment.');
    }

    /**
     * The other direction: the one allow-listed `whereNull('institution_id')` site must still
     * be there and still be doing what it is excused for, or the exclusion has quietly widened
     * the guard's blind spot for nothing. Same discipline as
     * `CalendarIsTheOnlyConverterTest::test_every_allow_listed_file_still_calls_strtotime`.
     */
    public function test_the_backfill_allow_list_is_not_stale(): void
    {
        $stale = [];

        foreach (self::WHERE_NULL_ALLOW_LIST as $relative) {
            $path = base_path($relative);

            if (! file_exists($path) || ! str_contains((string) file_get_contents($path), "whereNull('institution_id')")) {
                $stale[] = $relative;
            }
        }

        $this->assertSame([], $stale,
            'These allow-listed files no longer contain whereNull(\'institution_id\') (or no '
            .'longer exist) — remove them from WHERE_NULL_ALLOW_LIST: '.implode(', ', $stale));
    }
}
