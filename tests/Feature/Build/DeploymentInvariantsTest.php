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

        // `artisan migrate` in any form — the word appears in comments, so match a command.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*(?!#).*artisan\s+migrate/mi',
            $entrypoint,
            'the entrypoint must never run migrations',
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
}
