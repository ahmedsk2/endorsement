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
        $this->assertMatchesRegularExpression(
            '/TRUSTED_PROXIES:[^\n]*cloudflare/',
            $this->compose(),
            'the proxy list must name the Cloudflare edge or every audit IP is the CDN',
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

    public function test_the_deployment_sets_the_wards_timezone(): void
    {
        $this->assertStringContainsString(
            'APP_TIMEZONE: Asia/Riyadh',
            $this->compose(),
            'the container must be told which timezone the ward is in',
        );
    }
}
