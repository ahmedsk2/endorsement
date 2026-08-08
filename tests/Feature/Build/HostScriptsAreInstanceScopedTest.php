<?php

namespace Tests\Feature\Build;

use Tests\TestCase;

/**
 * P0d Task 6/7. Finding 1 is the highest-consequence item in the whole plan: the documented
 * migration procedure selected the database container by `ancestor=mysql:8.4`, and with two
 * customer stacks up that is an arbitrary choice — the GRANT ALTER / migrate / REVOKE
 * sequence can land on the wrong customer's clinical database, and if both use the default
 * MYSQL_DATABASE/MYSQL_USER names every command reports success. `docker/instance-env.sh`
 * replaces every such selector; this file guards that nothing reintroduces the old one, that
 * the literal Coolify UUID stays out of the scripts meant to work for any customer, and that
 * the two host scripts (finding 2: incorrect at N=2; finding 3: destructive at N=2) require an
 * instance slug rather than guessing.
 */
class HostScriptsAreInstanceScopedTest extends TestCase
{
    /**
     * Scanned, not enumerated: a list would go stale the moment a runbook is added.
     * `docs/superpowers/` is excluded because the plans and specs QUOTE the bad selector while
     * explaining why it was removed — this plan among them.
     *
     * @return list<string>
     */
    private function operatorDocs(): array
    {
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('docs'))) as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['md', 'sql'], true)) {
                continue;
            }

            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'superpowers'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $found[] = $file->getPathname();
        }

        $this->assertNotEmpty($found, 'expected to find operator documents under docs/');

        return $found;
    }

    public function test_no_operator_document_selects_a_container_by_image_ancestry(): void
    {
        foreach ($this->operatorDocs() as $path) {
            $this->assertStringNotContainsString(
                'ancestor=mysql',
                (string) file_get_contents($path),
                str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)
                .' selects a container by image ancestry; use docker/instance-env.sh',
            );
        }
    }

    /**
     * The Coolify app UUID is the identifier a human types into `instance-env.sh`; it is not
     * a secret and the runbooks name it deliberately. The scripts meant to run unmodified for
     * ANY customer must never hardcode it — that is exactly the drift finding 1 and finding 8's
     * sibling issues describe. Scoped to docker/, matching this plan's definition of done.
     */
    public function test_no_docker_script_hardcodes_the_first_customers_coolify_uuid(): void
    {
        $dir = base_path('docker');
        $this->assertDirectoryExists($dir);

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $this->assertStringNotContainsString(
                'oo7d7si62yhyi7fx10hrck6q',
                (string) file_get_contents($file->getPathname()),
                str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())
                .' hardcodes one customer\'s Coolify app UUID; it must take the UUID/slug as an argument',
            );
        }
    }

    public function test_instance_env_script_exists_and_is_executable_shell(): void
    {
        $path = base_path('docker/instance-env.sh');

        $this->assertFileExists($path);
        $this->assertStringStartsWith('#!/usr/bin/env bash', (string) file_get_contents($path));
    }

    /**
     * Finding 2: `docker/uptime-check.sh`'s LOG and STATE were absolute literals. Two crons
     * sharing one state file each read the other's last value, and the transition logic emits
     * a permanent stream of false CRITICAL/recovered pairs — corrupting the outage record.
     */
    public function test_uptime_check_no_longer_hardcodes_an_unscoped_state_file(): void
    {
        $this->assertStringNotContainsString(
            '/var/lib/endorsement-uptime.state',
            (string) file_get_contents(base_path('docker/uptime-check.sh')),
            'STATE must be derived from the instance slug, or two customers share one state file',
        );

        $this->assertStringNotContainsString(
            '/var/log/endorsement-uptime.log',
            (string) file_get_contents(base_path('docker/uptime-check.sh')),
            'LOG must be derived from the instance slug, or two customers share one log',
        );
    }

    public function test_uptime_check_requires_an_instance_slug(): void
    {
        $script = (string) file_get_contents(base_path('docker/uptime-check.sh'));

        $this->assertMatchesRegularExpression(
            '/slug="\$\{1:-\}"/',
            $script,
            'the slug must be a required positional argument, not defaulted',
        );
    }

    public function test_backup_offhost_sync_requires_an_instance_slug(): void
    {
        $script = (string) file_get_contents(base_path('docker/backup-offhost-sync.sh'));

        $this->assertMatchesRegularExpression(
            '/slug="\$\{1:-\}"/',
            $script,
            'the slug must be a required positional argument, not defaulted',
        );

        // The volume NAME (`endorsement-backups`) is correctly still literal — it is the
        // same for every customer's compose stack. What must never be pasted is the
        // Coolify app UUID that PREFIXES it, which differs per customer; it has to come out
        // of the per-slug config file as $PROJECT_UUID, not be typed into the script.
        $this->assertMatchesRegularExpression(
            '/SRC="\/var\/lib\/docker\/volumes\/\$\{PROJECT_UUID\}_endorsement-backups\/_data"/',
            $script,
            'the volume source must be derived from a PROJECT_UUID read out of the per-slug config, never pasted',
        );
    }

    /**
     * `docker/smoke.sh` deliberately brings up a SECOND `mysql:8.4` container on the same
     * host — which is exactly what makes the wrong-database hazard reachable during the
     * window an operator is most likely to be reading the runbook. Two concurrent smoke runs
     * must not collide on the same compose project and tear down each other's volumes.
     */
    public function test_smoke_script_project_name_is_overridable(): void
    {
        $this->assertStringContainsString(
            'PROJECT="${PROJECT:-endorse-smoke}"',
            (string) file_get_contents(base_path('docker/smoke.sh')),
            'a second concurrent smoke run must be able to pick its own compose project name',
        );
    }

    /**
     * Finding: the old header ran `docker exec -i $(docker ps -qf name=db-<uuid>) ...` with a
     * hardcoded schema/user baked into the GRANT/REVOKE lines. On a customer whose database is
     * not literally `endorsement`, the substitution silently no-ops on the wrong schema.
     */
    public function test_least_privilege_sql_cannot_run_unsubstituted(): void
    {
        $sql = (string) file_get_contents(base_path('docs/sql/least-privilege.sql'));

        $this->assertStringNotContainsString(
            '`endorsement`',
            $sql,
            'the schema name must be a placeholder substituted per customer, not the literal `endorsement`',
        );

        $this->assertStringNotContainsString(
            "'endorse'@",
            $sql,
            'the user name must be a placeholder substituted per customer, not the literal endorse user',
        );

        $this->assertStringContainsString('{{DATABASE}}', $sql);
        $this->assertStringContainsString('{{USER}}', $sql);
    }
}
