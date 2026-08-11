<?php

namespace Tests\Feature\Build;

use App\Support\TrustedProxies;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `.env.example` is two things at once, and it was only ever reviewed as one of them.
 *
 * It is CI'S ENTIRE ENVIRONMENT — `.github/workflows/ci.yml` does `cp .env.example .env` before
 * running the suite — and it is the `.env` EVERY FRESH CHECKOUT GETS, because `composer.json`'s
 * `post-root-package-install` and `post-create-project-cmd` both copy it when `.env` is absent.
 * So a wrong line here is wrong in two places, and it reaches a new developer silently.
 *
 * What it is NOT is the production path: a real deployment is configured through Coolify's
 * Environment Variables screen and `docker-compose.production.yml`'s `environment:` block
 * (`docs/RUNBOOK-PROVISION.md`), never by copying this file. That is precisely why the four
 * blank lines below survived — the file was reviewed as documentation, and the one consumer
 * that actually executes it had been unable to run since 2026-08-08.
 *
 * On 2026-08-11, the first CI run after the workflow was unblocked failed 459 of 1446 tests
 * against a tree that was green on every developer machine. The cause was four lines that looked
 * like documentation:
 *
 *     INSTANCE_SLUG=
 *     INSTITUTION_CODE=
 *     INSTITUTION_NAME=
 *     HIJRI_OFFSET_DAYS=
 *
 * In Laravel a key that is PRESENT BUT EMPTY resolves to `''`. A key that is ABSENT falls back to
 * `env()`'s second argument. So `env('INSTITUTION_CODE', 'QCH')` returned `''`, not `'QCH'`,
 * `ReferenceSeeder` anchored the deployment on an institution with no code and no name, and the
 * failure cascaded outward through institution_id provenance, the capability cache and every
 * capability-gated route. Nothing errored; the seed simply produced a different world.
 *
 * This distinction was already KNOWN here. `DeploymentInvariantsTest::
 * test_instance_and_institution_variables_reach_the_container` documents it at length and guards
 * it for `docker-compose.production.yml`, where the same trap is spelled `${VAR:-default}` —
 * that guard exists because P0d Task 9's dress rehearsal found a throwaway instance configured
 * `INSTITUTION_CODE=TSA` seeding as `QCH` anyway. The lesson was learned for compose and never
 * carried across to the file sitting next to it. That is why this guard asserts a PROPERTY over
 * the whole template rather than pinning the four keys that happened to break.
 *
 * The property: no key `.env.example` ships may resolve differently from that same key being
 * absent, unless the difference is deliberate and allow-listed here with a stated reason.
 *
 * Assertions are over the whole SET (`assertSame([], $offenders)`), never inside a `foreach` that
 * silently stops guarding once the last offender is fixed — the failure mode
 * `CompiledCssIsLightOnlyTest` documents and `CalendarWritersFlushTest` follows.
 */
class EnvExampleNeverNeutersADefaultTest extends TestCase
{
    /**
     * Keys `.env.example` ships EMPTY on purpose, each with the reason it is safe.
     *
     * An entry here waives the policy check below. It does NOT waive
     * `test_no_empty_key_neuters_a_code_side_default` — that one reads the code's own defaults
     * and cannot be silenced by editing this list, because there is no good reason to ship a
     * blank that overrides a default somebody wrote deliberately.
     */
    private const DELIBERATELY_EMPTY = [
        // MUST be present and empty. `php artisan key:generate` rewrites the file with
        // preg_replace over an existing `APP_KEY=` line; with the key ABSENT the replace matches
        // nothing, the key is never written, and CI boots with no application key at all.
        // Presence is load-bearing here, not cosmetic.
        'APP_KEY' => 'key:generate rewrites this line in place; absent, it has nothing to rewrite',
        // No `env()` call anywhere gives these a default, so `''` and `null` are the same thing
        // to every consumer: "no S3 configured". This deployment is FILESYSTEM_DISK=local and
        // never constructs the s3 disk. Kept visible because they are the conventional place an
        // operator looks when adding off-host storage.
        'AWS_ACCESS_KEY_ID' => 'no code-side default; empty and absent both mean "no S3 configured"',
        'AWS_SECRET_ACCESS_KEY' => 'no code-side default; empty and absent both mean "no S3 configured"',
        'AWS_BUCKET' => 'no code-side default; empty and absent both mean "no S3 configured"',
    ];

    /**
     * Keys the template pins even though their default is environment-derived, with the reason
     * that is harmless. Only one shape qualifies: the pinned value resolves to the SAME thing
     * the default would.
     */
    private const PINNED_ON_PURPOSE = [
        // `MAIL_FROM_NAME="${APP_NAME}"`, against a default of `env('APP_NAME', 'Laravel')`.
        // Dotenv interpolates `${APP_NAME}` from this same file, so the pinned value and the
        // default read the identical variable and cannot disagree. Kept spelled out because it
        // is the line that shows an operator the sender name is theirs to change.
        'MAIL_FROM_NAME' => 'interpolates ${APP_NAME}, which is exactly what its default reads',
    ];

    private function template(): string
    {
        return (string) file_get_contents(base_path('.env.example'));
    }

    /**
     * The keys the template actually SHIPS, mapped to their raw values. A commented line is not
     * shipped — that is the whole point of commenting it, and it is the fix this guard pushes
     * you toward.
     *
     * @return array<string, string>
     */
    private function shippedKeys(): array
    {
        $shipped = [];

        foreach (preg_split('/\R/', $this->template()) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            // `KEY=""` is as empty as `KEY=` once dotenv has read it.
            $shipped[trim($key)] = trim(trim(trim($value), '"\''));
        }

        return $shipped;
    }

    /** @return list<string> */
    private function emptyShippedKeys(): array
    {
        return array_keys(array_filter($this->shippedKeys(), static fn (string $v): bool => $v === ''));
    }

    /**
     * Every `env('KEY', <default>)` in config/ and app/ whose default is NOT itself empty.
     *
     * The "not itself empty" part is not a nicety. `AUDIT_KEY`, `BACKUP_PASSPHRASE`,
     * `DB_PASSWORD`, `DB_SOCKET`, `LEGACY_DB_PASSWORD` and `APP_PREVIOUS_KEYS` all default to
     * `''`, so for those a blank line in the template resolves to exactly what absence resolves
     * to and there is nothing to report. Flagging them would be a false positive, and a guard
     * that cries wolf is a guard someone deletes.
     *
     * app/ is scanned as well as config/ because `App\Support\TrustedProxies::list()` reads
     * `env('TRUSTED_PROXIES', self::DEFAULT)` directly — a code-side default living outside
     * config/ is still a default a blank line would neuter.
     *
     * @return array<string, string> key => the default expression, verbatim, for the message
     */
    private function defaultsThatABlankWouldNeuter(): array
    {
        $defaults = [];

        foreach ([config_path(), app_path()] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                preg_match_all(
                    "/env\(\s*'([A-Z0-9_]+)'\s*,\s*([^,)]+)/",
                    (string) file_get_contents($file->getPathname()),
                    $matches,
                    PREG_SET_ORDER,
                );

                foreach ($matches as [, $key, $default]) {
                    $default = trim($default);

                    if ($default === "''" || $default === '""' || $default === 'null') {
                        continue;
                    }

                    $defaults[$key] = $default;
                }
            }
        }

        return $defaults;
    }

    /**
     * The defect itself, stated as a property. NOT waivable by the allow-list.
     */
    public function test_no_empty_key_neuters_a_code_side_default(): void
    {
        $defaults = $this->defaultsThatABlankWouldNeuter();
        $offenders = [];

        foreach ($this->emptyShippedKeys() as $key) {
            if (isset($defaults[$key])) {
                $offenders[] = "{$key} (blank here, but the code says env('{$key}', {$defaults[$key]}))";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A key that is PRESENT BUT EMPTY in .env.example resolves to '' and silently replaces "
            .'the default the code declares — it does NOT fall back to it. CI copies this file '
            ."verbatim, so this breaks the build; an operator copies it too, so it also ships.\n"
            ."Comment the key out (the default then applies) or give it a real value:\n"
            .implode("\n", $offenders)
        );
    }

    /**
     * The general policy, one step broader than the defect: an empty key is a decision, so it
     * has to be written down as one. Catches keys with no code-side default today that acquire
     * one later — the exact way HIJRI_OFFSET_DAYS would have become a clinical bug.
     */
    public function test_no_shipped_key_is_empty_without_a_stated_reason(): void
    {
        $offenders = array_values(array_diff(
            $this->emptyShippedKeys(),
            array_keys(self::DELIBERATELY_EMPTY),
        ));

        $this->assertSame(
            [],
            $offenders,
            '.env.example ships these keys present-but-empty, which resolves to \'\' and not to '
            ."the code's default. Comment them out unless the blank is deliberate — and if it is, "
            ."add them to DELIBERATELY_EMPTY with the reason:\n".implode("\n", $offenders)
        );
    }

    /**
     * The other direction, same discipline as `CalendarWritersFlushTest::
     * test_the_allow_list_is_not_stale`: an allow-list entry for a key the template no longer
     * ships empty is dead text that makes the next reader trust a waiver that guards nothing.
     */
    public function test_the_deliberately_empty_list_is_not_stale(): void
    {
        $stale = array_values(array_diff(
            array_keys(self::DELIBERATELY_EMPTY),
            $this->emptyShippedKeys(),
        ));

        $this->assertSame(
            [],
            $stale,
            'These keys are allow-listed as deliberately empty but .env.example no longer ships '
            .'them empty — remove them from DELIBERATELY_EMPTY: '.implode(', ', $stale)
        );
    }

    /**
     * A default that is COMPUTED FROM THE ENVIRONMENT must not be pinned by a file that is
     * copied into every environment.
     *
     * `config/endorsement.php` declares
     * `env('REQUIRE_2FA_PRIVILEGED', env('APP_ENV') === 'production')`, and
     * `config/session.php` does the same for `SESSION_SECURE_COOKIE`. The code already adapts:
     * on in production, off in development and under test. A template that hardcodes one of
     * these hands the same answer to all three, so at least one environment is guaranteed to
     * get the wrong one — and this template hardcoded `REQUIRE_2FA_PRIVILEGED=true` in a file
     * whose own `APP_ENV` is `local`, which is a contradiction read literally.
     *
     * That single line was the SECOND half of the 2026-08-11 CI failure, and it survived the
     * fix to the four empty keys above: `EnforceTwoFactor` then challenged every privileged
     * account, so each admin route answered 302 instead of 200 and 384 tests still failed on a
     * tree that was green locally. It was invisible to the empty-key checks because the value
     * is not empty — it is simply not the template's business. Commenting it out costs
     * production nothing, because the default it restores already evaluates to `true` there.
     */
    public function test_the_template_does_not_pin_an_environment_derived_default(): void
    {
        $shipped = array_keys($this->shippedKeys());
        $found = [];

        foreach ([config_path(), app_path()] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                // A default that itself calls env() reads the surrounding environment.
                preg_match_all(
                    "/env\(\s*'([A-Z0-9_]+)'\s*,\s*(env\([^)]*\)[^,)]*)/",
                    (string) file_get_contents($file->getPathname()),
                    $matches,
                    PREG_SET_ORDER,
                );

                foreach ($matches as [, $key, $default]) {
                    if (in_array($key, $shipped, true)) {
                        $found[$key] = trim($default);
                    }
                }
            }
        }

        $offenders = [];

        foreach ($found as $key => $default) {
            if (! isset(self::PINNED_ON_PURPOSE[$key])) {
                $offenders[] = "{$key} (the code already decides this per environment: "
                    ."env('{$key}', {$default}))";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These keys have a code-side default computed FROM the environment, and .env.example '
            .'is copied into every environment — CI, a developer laptop and a new customer alike. '
            ."Pinning one here overrides that adaptation everywhere at once. Comment it out:\n"
            .implode("\n", $offenders)
        );

        // The other direction, so a waiver cannot outlive the thing it waives.
        $this->assertSame(
            [],
            array_values(array_diff(array_keys(self::PINNED_ON_PURPOSE), array_keys($found))),
            'PINNED_ON_PURPOSE lists a key that .env.example no longer pins (or whose default is '
            .'no longer environment-derived) — remove it.'
        );
    }

    /**
     * The same class of defect one turn of the screw further, and the reason this file does not
     * stop at empty keys: a NON-empty value can neuter a default too.
     *
     * `TrustedProxies::list()` reads `env('TRUSTED_PROXIES', self::DEFAULT)`, so setting the
     * variable to ANYTHING means `self::DEFAULT` — the three RFC1918 ranges — never applies.
     * The template shipped `TRUSTED_PROXIES=*`; the wildcard is correctly refused entry-by-entry
     * (CLAUDE.md: never `*`), but the mere PRESENCE of the line dropped the private ranges,
     * leaving Cloudflare's ranges alone. Behind Traefik the peer address is private, so it would
     * then be untrusted, X-Forwarded-For would be ignored wholesale, and every audit IP would
     * record the proxy instead of the clinician — which is the "trust too little" failure
     * `TrustedProxies`' own docblock says this deployment already shipped once, on 2026-07-27.
     *
     * Measured, not reasoned: with the line present the list was 22 entries and 10.0.0.0/8 was
     * absent; commented out it is 25 and present.
     */
    public function test_the_template_does_not_narrow_the_trusted_proxy_list(): void
    {
        $configured = $this->shippedKeys()['TRUSTED_PROXIES'] ?? null;

        if ($configured === null) {
            $this->assertContains(
                '10.0.0.0/8',
                TrustedProxies::list(),
                'with TRUSTED_PROXIES unset the code default must still trust the private ranges',
            );

            return;
        }

        $missing = array_values(array_diff(
            explode(',', TrustedProxies::DEFAULT),
            TrustedProxies::list($configured),
        ));

        $this->assertSame(
            [],
            $missing,
            "TRUSTED_PROXIES is set in .env.example, which means TrustedProxies::DEFAULT never "
            ."applies — and these private ranges are lost as a result:\n".implode("\n", $missing)
            ."\nComment the key out so the code-side default (private ranges + the Cloudflare "
            .'edge) governs, or spell the ranges out in full.'
        );
    }
}
