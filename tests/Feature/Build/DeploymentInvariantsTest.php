<?php

namespace Tests\Feature\Build;

use Tests\TestCase;

/**
 * The deployment invariants, guarded in CI rather than in someone's memory.
 *
 * Every assertion here corresponds to something that actually broke, or that would break
 * silently on a redeploy months from now when nobody remembers why the line was there.
 * These are cheap file reads — they cost nothing and they run on every push.
 */
class DeploymentInvariantsTest extends TestCase
{
    private function dockerfile(): string
    {
        return (string) file_get_contents(base_path('Dockerfile'));
    }

    private function compose(): string
    {
        return (string) file_get_contents(base_path('docker-compose.production.yml'));
    }

    /**
     * A tag is mutable. `php:8.4-fpm-alpine` today and in six months are different bits, so
     * an unpinned rebuild — which is exactly what a Coolify "redeploy last good" performs —
     * can ship something that was never tested, and a rollback can differ from the thing it
     * is rolling back to.
     */
    public function test_every_base_image_is_pinned_by_digest(): void
    {
        preg_match_all('/^FROM\s+(\S+)/mi', $this->dockerfile(), $m);

        $this->assertNotEmpty($m[1], 'no FROM lines found');

        foreach ($m[1] as $image) {
            $this->assertStringContainsString(
                '@sha256:',
                $image,
                "base image [{$image}] is on a mutable tag; pin it by digest",
            );
        }
    }

    public function test_the_database_image_is_pinned_by_digest(): void
    {
        $this->assertMatchesRegularExpression(
            '/image:\s*mysql:[^\s@]+@sha256:[0-9a-f]{64}/',
            $this->compose(),
            'the mysql image must be digest-pinned like the Dockerfile bases',
        );
    }

    /**
     * The 2026-07-27 outage. The app container is on three docker networks and
     * coolify-proxy is not on all of them; with no hint Traefik picks one to dial, and Go
     * randomises map iteration, so the choice can differ on every deploy. Picking the
     * internal network means every request times out and the site returns 504 while the
     * container sits there healthy.
     */
    public function test_traefik_is_told_which_network_to_use(): void
    {
        $this->assertStringContainsString(
            'traefik.docker.network=coolify',
            $this->compose(),
            'without this hint Traefik guesses, and guessing wrong is a total outage',
        );
    }

    /**
     * The other half of the same problem: Coolify generates the router from its Domains
     * field, so a router defined here would compete for the same host.
     */
    public function test_the_compose_file_defines_no_competing_router(): void
    {
        foreach (['traefik.http.routers', 'traefik.http.services'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $this->compose(),
                "[{$forbidden}] would create a second router competing with Coolify's",
            );
        }
    }

    /**
     * Production migrations are the owner's to run. A container that migrates at boot can
     * alter a clinical schema during an unattended 3am restart.
     */
    public function test_the_entrypoint_never_migrates(): void
    {
        $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));

        // Catches EXECUTION only. `migrate:status` is a read and is deliberately present —
        // the entrypoint warns when the schema is behind the code — and the same string
        // appears inside an echo telling the operator what to run. A guard broad enough to
        // reject those is a guard that blocks the right fix, so it excludes both.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*(?!#)(?!.*echo).*artisan\s+migrate(?!:status)/mi',
            $entrypoint,
            'the entrypoint must never RUN migrations (reading their status is fine)',
        );

        // And prove the warning it replaces them with is actually there.
        $this->assertStringContainsString(
            'migrate:status',
            $entrypoint,
            'the entrypoint should warn when the schema is behind the code',
        );
    }

    /**
     * SPC-RPT-006. The entrypoint chowns bootstrap/cache to `app` and then, as root, runs
     * artisan commands that bootstrap the framework out of that same directory. Anything
     * able to write there as `app` therefore executes as uid 0 on the next restart — and
     * restarts happen unattended.
     *
     * This is the finding itself, not the ambient "daemons run as root" observation. It is
     * closed by dropping privileges for those four commands, which is cheap; it was once
     * fixed and then reverted as collateral damage from an unrelated `USER app` attempt.
     */
    public function test_no_boot_time_artisan_command_runs_as_root(): void
    {
        $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));

        // Executions only — the file also prints `php artisan migrate --force` inside an
        // echo, telling the operator what to run themselves.
        preg_match_all('/^(?!\s*#)(?!.*echo).*\bphp\s+artisan\b.*$/mi', $entrypoint, $m);

        $this->assertNotEmpty($m[0], 'expected the entrypoint to run artisan at boot');

        foreach ($m[0] as $line) {
            $this->assertStringContainsString(
                'su-exec app php artisan',
                trim($line),
                'boot-time artisan must drop to `app`; as root it executes app-writable PHP',
            );
        }
    }

    public function test_the_app_container_drops_its_capabilities(): void
    {
        $compose = $this->compose();

        $this->assertStringContainsString('no-new-privileges:true', $compose);
        $this->assertMatchesRegularExpression(
            '/cap_drop:\s*(\n\s*-\s*ALL|\[\s*ALL\s*\])/',
            $compose,
            'the app container should start from no capabilities and add back only what it needs',
        );
    }

    /**
     * This build runs as root on the same docker daemon as the patient database, so a
     * dependency's postinstall script would execute in the worst possible place.
     */
    public function test_the_npm_install_policy_reaches_the_build(): void
    {
        $this->assertStringContainsString(
            'ignore-scripts=true',
            (string) file_get_contents(base_path('.npmrc')),
        );

        $this->assertMatchesRegularExpression(
            '/COPY\s+[^\n]*\.npmrc/',
            $this->dockerfile(),
            '.npmrc must be copied into the build or the policy only applies on a laptop',
        );
    }

    /**
     * `.env` in a layer is a credential leak that survives every later instruction.
     */
    public function test_secrets_and_dev_state_stay_out_of_the_image(): void
    {
        $ignore = (string) file_get_contents(base_path('.dockerignore'));

        foreach (['.env', 'bootstrap/cache/*.php', 'storage/app/private'] as $path) {
            $this->assertStringContainsString($path, $ignore, "[{$path}] must be excluded from the image");
        }
    }

    /**
     * Hardcoded to 'UTC', config/app.php silently ignored APP_TIMEZONE — so a container
     * configured for the ward still ran three hours behind it. The handover reminders that
     * this system exists to send fired at 10:30 and 18:30 local instead of 07:30 and 15:30,
     * and the day boundary sat at 03:00, filing night-shift entries under the wrong date.
     */
    public function test_the_application_timezone_comes_from_the_environment(): void
    {
        $this->assertMatchesRegularExpression(
            "/'timezone'\s*=>\s*env\(\s*'APP_TIMEZONE'/",
            (string) file_get_contents(config_path('app.php')),
            'config/app.php must read APP_TIMEZONE or setting it in the environment does nothing',
        );
    }

    /**
     * The app sits behind Cloudflare, so Traefik appends the CDN edge as the last forwarded
     * hop. Without the edge ranges in the trust list the audit trail records that CDN as the
     * actor for every clinical action, and the per-IP lockout is keyed on an address shared
     * by everyone behind the same edge. It fails silently — there is no error to notice.
     */
    public function test_the_deployment_trusts_the_cloudflare_edge(): void
    {
        // Asserted against the CODE, not the compose default. Coolify sets TRUSTED_PROXIES
        // explicitly, so `${TRUSTED_PROXIES:-...}` is dead code on this deployment — the
        // first version of this fix shipped green and changed nothing at all.
        $this->assertStringContainsString(
            'TrustedProxies::list()',
            (string) file_get_contents(base_path('bootstrap/app.php')),
            'the client IP must be resolved through the audited proxy list',
        );

        $this->assertNotEmpty(
            array_intersect(
                \App\Support\TrustedProxies::CLOUDFLARE,
                \App\Support\TrustedProxies::list('10.0.0.0/8'),
            ),
            'the edge must be trusted without the environment having to ask, or every audit IP is the CDN',
        );
    }

    /**
     * The other direction: a wildcard means Symfony believes the client-supplied leftmost
     * X-Forwarded-For entry — forgeable audit IPs and a lockout escaped with a header.
     */
    public function test_the_proxy_list_is_never_a_wildcard_anywhere(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/TRUSTED_PROXIES[^\n]*[:=][^\n]*\*/',
            $this->compose(),
        );

        $this->assertStringNotContainsString(
            "env('TRUSTED_PROXIES', '*')",
            (string) file_get_contents(base_path('bootstrap/app.php')),
            'a wildcard fallback puts one missing variable between this app and a forgeable trail',
        );
    }

    /**
     * Hardcoded to 'UTC', config/app.php once ignored APP_TIMEZONE entirely and the container
     * ran three hours behind the ward: reminders at 10:30 and 18:30 instead of 07:30 and 15:30,
     * and a day boundary at 03:00 filing night-shift entries under the wrong date.
     *
     * Since D11 there is one deployment per customer and a customer may not be in the Kingdom,
     * so the value is a variable — but it keeps Asia/Riyadh as its default, because an
     * unset variable must not silently mean UTC and reintroduce the original fault.
     */
    public function test_the_deployment_timezone_is_settable_and_defaults_to_the_ward(): void
    {
        $this->assertMatchesRegularExpression(
            '/APP_TIMEZONE:\s*\$\{APP_TIMEZONE:-Asia\/Riyadh\}/',
            $this->compose(),
            'APP_TIMEZONE must be per-customer AND default to Asia/Riyadh: a customer outside '
            .'Saudi Arabia cannot edit the shared compose file, and an unset variable must not '
            .'mean UTC.',
        );
    }

    /**
     * P0d Task 1 taught `App\Support\Instance::slug()` to read `INSTANCE_SLUG`, and Task 3
     * taught `ReferenceSeeder` to read `INSTITUTION_CODE`/`INSTITUTION_NAME` — but neither task
     * added the variable to THIS file's `environment:` block, so a value set in Coolify's
     * Environment Variables screen (exactly what `docs/RUNBOOK-PROVISION.md` and
     * `scripts/new-instance.sh`'s printed block tell the owner to do) never reached the
     * container's process environment at all: `--env-file`/Coolify variables are available for
     * `${...}` interpolation WITHIN this compose file, not injected into the container unless a
     * service's `environment:` block references them. Found empirically in Task 9's dress
     * rehearsal — a throwaway instance configured with `INSTITUTION_CODE=TSA` seeded as `QCH`
     * anyway, and `instance:show` reported the derived fallback slug instead of the configured
     * one.
     *
     * The defaults matter as much as the passthrough: Laravel's `env('X', 'default')` returns
     * `''`, NOT `'default'`, when the variable is PRESENT but empty — which is exactly what a
     * bare `${INSTITUTION_CODE}` (no `:-` fallback) would put in the container's environment on
     * the EXISTING live deployment, which has never set it in Coolify. That would have silently
     * turned the live QCH institution's every re-seed into an empty institution code — a
     * regression this plan's own premise ("today's value as its default") forbids. So the
     * default must live in the compose interpolation, in the same `${VAR:-default}` shape as
     * `APP_TIMEZONE`/`TRUSTED_PROXIES` above, not only in `config/endorsement.php`.
     */
    public function test_instance_and_institution_variables_reach_the_container(): void
    {
        $compose = $this->compose();

        $this->assertMatchesRegularExpression(
            '/INSTANCE_SLUG:\s*\$\{INSTANCE_SLUG:-\}/',
            $compose,
            'INSTANCE_SLUG must be passed through to the container (App\Support\Instance::slug() '
            .'reads it via env()); an unset default of empty string is correct — Instance::slug() '
            .'already treats an empty value as unconfigured and derives from APP_NAME.',
        );

        $this->assertMatchesRegularExpression(
            '/INSTITUTION_CODE:\s*\$\{INSTITUTION_CODE:-QCH\}/',
            $compose,
            'INSTITUTION_CODE must be passed through AND default to QCH in the compose file '
            .'itself — the existing live deployment has never set it in Coolify, and env() '
            .'returns an empty string (not the PHP-side default) for a variable that is present '
            .'but empty.',
        );

        $this->assertMatchesRegularExpression(
            '/INSTITUTION_NAME:\s*\$\{INSTITUTION_NAME:-Qatif Central Hospital\}/',
            $compose,
            'INSTITUTION_NAME must be passed through AND default to "Qatif Central Hospital" in '
            .'the compose file itself, for the same reason as INSTITUTION_CODE above.',
        );
    }

    /**
     * P1a Task 1 taught `config/endorsement.php` and `ReferenceSeeder` to read
     * `HIJRI_OFFSET_DAYS`, but — same defect as `test_instance_and_institution_variables_reach_
     * the_container` above, found in P0d Task 9's dress rehearsal — neither of those wires a
     * variable into THIS file's `environment:` block. A value pasted into Coolify's
     * Environment Variables screen has zero effect unless it is referenced here.
     *
     * The default matters as much as the passthrough, for the same reason as
     * INSTITUTION_CODE/NAME: `env('X', 'default')` returns `''`, NOT `'default'`, for a
     * variable that is PRESENT but empty — which is what a bare `${HIJRI_OFFSET_DAYS}` (no
     * `:-` fallback) would put in the container's environment for every deployment that has
     * never set it in Coolify, silently rendering every Hijri date one day wrong.
     */
    public function test_hijri_offset_reaches_the_container(): void
    {
        $this->assertMatchesRegularExpression(
            '/HIJRI_OFFSET_DAYS:\s*\$\{HIJRI_OFFSET_DAYS:-0\}/',
            $this->compose(),
            'HIJRI_OFFSET_DAYS must be passed through to the container AND default to 0 in the '
            .'compose file itself — a value set only in config/ or .env.example never reaches '
            .'the process environment unless this environment: block references it.',
        );
    }
}
