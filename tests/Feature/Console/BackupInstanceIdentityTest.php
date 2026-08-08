<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use App\Support\Instance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The archive filename gained no way to say which customer it belongs to, while its own
 * retention sweep deletes across every customer sharing the directory (P0d finding 3). This
 * pins the fix: the instance slug in the filename, a prune glob anchored on the timestamp
 * shape so slug `qch` cannot swallow slug `qch-2` (finding 5), and a warning — never a widened
 * glob — for the fourteen legacy archives the live deployment already has (finding 4).
 */
class BackupInstanceIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('BACKUP_PASSPHRASE=');
        config(['endorsement.instance.slug' => null]);

        parent::tearDown();
    }

    private function hasOpenssl(): bool
    {
        $probe = new Process(PHP_OS_FAMILY === 'Windows' ? ['where', 'openssl'] : ['which', 'openssl']);
        $probe->run();

        return $probe->isSuccessful();
    }

    /** A fresh, real SQLite source database (the suite normally runs :memory:). */
    private function sqliteSource(string $name): string
    {
        $source = storage_path('framework/testing/'.$name);
        @mkdir(dirname($source), 0777, true);
        file_put_contents($source, '');
        $pdo = new \PDO('sqlite:'.$source);
        $pdo->exec('CREATE TABLE handovers (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO handovers (id) VALUES (1)');
        $pdo = null;

        return $source;
    }

    private function useSqliteSource(string $source): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $source]);
    }

    public function test_the_archive_and_signature_carry_the_instance_slug(): void
    {
        if (! $this->hasOpenssl()) {
            $this->markTestSkipped('openssl is not on PATH in this environment.');
        }

        config(['endorsement.instance.slug' => 'qch']);
        $this->useSqliteSource($this->sqliteSource('backup-slug-source.sqlite'));

        $sigDir = storage_path('app/private/signatures');
        @mkdir($sigDir, 0777, true);
        file_put_contents($sigDir.DIRECTORY_SEPARATOR.'deadbeef.png', str_repeat('P', 300));

        putenv('BACKUP_PASSPHRASE=a-long-test-passphrase-1234567890');
        $dir = storage_path('framework/testing/backups-slug');

        $this->artisan('backup:run', ['--path' => $dir])->assertExitCode(0);

        $archives = glob($dir.DIRECTORY_SEPARATOR.'endorsement-qch-*.sql.gz.enc') ?: [];
        $this->assertCount(1, $archives, 'the archive must be named with the configured instance slug');
        $this->assertMatchesRegularExpression(
            '/endorsement-qch-\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz\.enc$/',
            $archives[0],
        );

        $signatures = glob($dir.DIRECTORY_SEPARATOR.'endorsement-signatures-qch-*.tar.gz.enc') ?: [];
        $this->assertCount(1, $signatures, 'the signature archive must be named with the configured instance slug');

        array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);
        @unlink($sigDir.DIRECTORY_SEPARATOR.'deadbeef.png');
    }

    public function test_a_meta_json_sidecar_is_written_with_no_secret_and_no_phi(): void
    {
        if (! $this->hasOpenssl()) {
            $this->markTestSkipped('openssl is not on PATH in this environment.');
        }

        config(['endorsement.instance.slug' => 'qch']);
        $source = $this->sqliteSource('backup-meta-source.sqlite');
        $this->useSqliteSource($source);

        $passphrase = 'a-long-test-passphrase-1234567890';
        putenv('BACKUP_PASSPHRASE='.$passphrase);
        $dir = storage_path('framework/testing/backups-meta');

        $this->artisan('backup:run', ['--path' => $dir])->assertExitCode(0);

        $archives = glob($dir.DIRECTORY_SEPARATOR.'endorsement-qch-*.sql.gz.enc') ?: [];
        $this->assertCount(1, $archives);

        $sidecar = $archives[0].'.meta.json';
        $this->assertFileExists($sidecar, 'a .meta.json sidecar must be written beside the archive');

        $raw = (string) file_get_contents($sidecar);
        $meta = json_decode($raw, true);

        $this->assertIsArray($meta);
        $this->assertSame('qch', $meta['slug']);
        $this->assertIsString($meta['stamp']);
        $this->assertIsInt($meta['bytes']);
        $this->assertGreaterThan(0, $meta['bytes']);
        $this->assertSame(Instance::keyFingerprint(), $meta['app_key_fingerprint']);
        $this->assertIsString($meta['hostname']);
        $this->assertNotSame('', $meta['hostname']);

        // No passphrase, no key, no path outside $dir, nothing resembling a database secret.
        $this->assertStringNotContainsString($passphrase, $raw);
        $this->assertStringNotContainsString((string) config('app.key'), $raw);
        $this->assertStringNotContainsString($dir, $raw);
        $this->assertStringNotContainsString('BACKUP_PASSPHRASE', $raw);

        array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);
    }

    /**
     * Finding 5: `endorsement-qch-*` would otherwise match
     * `endorsement-qch-2-2026-08-08_013000.sql.gz.enc`. Two customers named `qch` and `qch-2`
     * sharing a pull directory must not eat each other's retention.
     */
    public function test_pruning_by_slug_does_not_touch_a_neighbouring_slug_that_shares_a_prefix(): void
    {
        if (! $this->hasOpenssl()) {
            $this->markTestSkipped('openssl is not on PATH in this environment.');
        }

        config(['endorsement.instance.slug' => 'qch']);
        $source = $this->sqliteSource('backup-collision-source.sqlite');
        $this->useSqliteSource($source);

        $dir = storage_path('framework/testing/backups-collision');
        @mkdir($dir, 0777, true);
        array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);

        $qchStamps = [];
        $qch2Stamps = [];

        for ($day = 1; $day <= 20; $day++) {
            $stamp = sprintf('2020-01-%02d_010000', $day);
            $qchStamps[] = $stamp;
            $qch2Stamps[] = $stamp;

            touch($dir.DIRECTORY_SEPARATOR."endorsement-qch-{$stamp}.sql.gz.enc");
            touch($dir.DIRECTORY_SEPARATOR."endorsement-qch-2-{$stamp}.sql.gz.enc");
        }

        putenv('BACKUP_PASSPHRASE=a-long-test-passphrase-1234567890');
        $this->artisan('backup:run', ['--path' => $dir, '--keep' => 14])->assertExitCode(0);

        // Every qch-2 archive must survive untouched — it belongs to a different customer.
        foreach ($qch2Stamps as $stamp) {
            $this->assertFileExists(
                $dir.DIRECTORY_SEPARATOR."endorsement-qch-2-{$stamp}.sql.gz.enc",
                "qch-2's archive for {$stamp} must not be deleted by qch's retention sweep",
            );
        }

        // qch: 20 seeded + 1 written by this run = 21; keep=14 prunes the oldest 7 seeded ones.
        $deleted = array_slice($qchStamps, 0, 7);
        $kept = array_slice($qchStamps, 7);

        foreach ($deleted as $stamp) {
            $this->assertFileDoesNotExist(
                $dir.DIRECTORY_SEPARATOR."endorsement-qch-{$stamp}.sql.gz.enc",
                "qch's oldest archive for {$stamp} should have been pruned",
            );
        }

        foreach ($kept as $stamp) {
            $this->assertFileExists(
                $dir.DIRECTORY_SEPARATOR."endorsement-qch-{$stamp}.sql.gz.enc",
                "qch's archive for {$stamp} is within the retained 14 and must survive",
            );
        }

        $qchRemaining = glob($dir.DIRECTORY_SEPARATOR.'endorsement-qch-*.sql.gz.enc') ?: [];
        $qch2Remaining = glob($dir.DIRECTORY_SEPARATOR.'endorsement-qch-2-*.sql.gz.enc') ?: [];

        $this->assertCount(14, array_diff($qchRemaining, $qch2Remaining), 'qch must retain exactly 14 archives');
        $this->assertCount(20, $qch2Remaining, 'qch-2 must retain every one of its 20 archives');

        array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);
    }

    /**
     * Finding 4: after this task lands, the live instance's fourteen un-slugged archives no
     * longer match any glob. The fix is a warning, never a widened pattern — widening
     * reintroduces the cross-customer delete finding 3 describes.
     */
    public function test_unslugged_legacy_archives_are_left_alone_and_warned_about(): void
    {
        if (! $this->hasOpenssl()) {
            $this->markTestSkipped('openssl is not on PATH in this environment.');
        }

        config(['endorsement.instance.slug' => 'qch']);
        $source = $this->sqliteSource('backup-legacy-source.sqlite');
        $this->useSqliteSource($source);

        $dir = storage_path('framework/testing/backups-legacy');
        @mkdir($dir, 0777, true);
        array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);

        $legacyStamps = ['2019-01-01_010000', '2019-01-02_010000', '2019-01-03_010000'];

        foreach ($legacyStamps as $stamp) {
            touch($dir.DIRECTORY_SEPARATOR."endorsement-{$stamp}.sql.gz.enc");
        }

        putenv('BACKUP_PASSPHRASE=a-long-test-passphrase-1234567890');
        // Artisan::call()/::output() rather than $this->artisan()->expectsOutputToContain():
        // the latter's Mockery expectation must be registered BEFORE the command runs, and
        // this assertion only needs the real captured output, not a call-order mock.
        $exitCode = Artisan::call('backup:run', ['--path' => $dir]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('3 archive(s)', $output);

        foreach ($legacyStamps as $stamp) {
            $this->assertFileExists(
                $dir.DIRECTORY_SEPARATOR."endorsement-{$stamp}.sql.gz.enc",
                'legacy un-slugged archives must never be deleted by the new, slug-scoped prune',
            );
        }

        array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);
    }

    public function test_the_audit_row_records_the_instance_without_a_path_or_phi(): void
    {
        if (! $this->hasOpenssl()) {
            $this->markTestSkipped('openssl is not on PATH in this environment.');
        }

        config(['endorsement.instance.slug' => 'qch']);
        $source = $this->sqliteSource('backup-audit-source.sqlite');
        $this->useSqliteSource($source);

        putenv('BACKUP_PASSPHRASE=a-long-test-passphrase-1234567890');
        $dir = storage_path('framework/testing/backups-audit');

        $this->artisan('backup:run', ['--path' => $dir])->assertExitCode(0);

        $row = AuditLog::query()->where('action', 'backup_created')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertStringContainsString('instance=qch', (string) $row->detail);
        $this->assertMatchesRegularExpression('/bytes=\d+/', (string) $row->detail);
        $this->assertMatchesRegularExpression('/seconds=[\d.]+/', (string) $row->detail);
        $this->assertStringNotContainsString($dir, (string) $row->detail);

        array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);
    }
}
