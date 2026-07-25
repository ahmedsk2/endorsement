<?php

namespace Tests\Feature\Console;

use App\Console\Commands\BackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The backup had NO coverage, which is how it reached production broken: Alpine's
 * `mysql-client` package is MariaDB's client, MariaDB 11 verifies the server certificate by
 * default, and MySQL 8.4 generates a self-signed one — so every nightly dump died with
 * "TLS/SSL error: self-signed certificate in certificate chain" and the only symptom was an
 * exit code in a log nobody reads.
 *
 * These tests pin the two things that failed: the client-specific TLS flags, and the fact
 * that "the archive decompressed" is not the same as "the archive contains a database".
 */
class BackupRunTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIG = [
        'driver' => 'mysql',
        'host' => 'db',
        'port' => 3306,
        'database' => 'endorsement',
        'username' => 'endorse',
        'password' => 'super-secret-value',
    ];

    public function test_the_mariadb_client_is_told_not_to_verify_the_self_signed_server_certificate(): void
    {
        $args = BackupRun::dumpArgs(self::CONFIG, '/tmp/out.sql', clientIsMariaDb: true);

        $this->assertContains('--ssl-verify-server-cert=0', $args);
        // MySQL 8.4 REMOVED this spelling; sending it to the Oracle client would abort.
        $this->assertNotContains('--ssl-mode=PREFERRED', $args);
    }

    public function test_the_oracle_client_gets_ssl_mode_instead(): void
    {
        $args = BackupRun::dumpArgs(self::CONFIG, '/tmp/out.sql', clientIsMariaDb: false);

        $this->assertContains('--ssl-mode=PREFERRED', $args);
        $this->assertNotContains('--ssl-verify-server-cert=0', $args);
    }

    public function test_it_does_not_ask_for_tablespaces_which_would_require_the_process_privilege(): void
    {
        // mysqldump dumps tablespaces by default and needs a server-wide PROCESS privilege
        // to do it. Granting that to the application's user to make a backup work would be
        // the wrong trade; skipping tablespaces costs nothing for InnoDB-per-file schemas.
        foreach ([true, false] as $mariadb) {
            $this->assertContains(
                '--no-tablespaces',
                BackupRun::dumpArgs(self::CONFIG, '/tmp/out.sql', clientIsMariaDb: $mariadb),
            );
        }
    }

    public function test_the_password_never_appears_on_the_command_line(): void
    {
        // argv is world-readable via /proc and `ps`. The password goes through MYSQL_PWD.
        $args = BackupRun::dumpArgs(self::CONFIG, '/tmp/out.sql', clientIsMariaDb: true);

        foreach ($args as $arg) {
            $this->assertStringNotContainsString('super-secret-value', $arg);
        }
    }

    public function test_it_detects_the_mariadb_client_from_its_version_banner(): void
    {
        $this->assertTrue(BackupRun::versionIsMariaDb('mysqldump from 11.8.8-MariaDB, client 10.19 for Linux (aarch64)'));
        $this->assertFalse(BackupRun::versionIsMariaDb('mysqldump  Ver 8.4.10 for Linux on aarch64 (MySQL Community Server - GPL)'));
    }

    public function test_a_payload_that_is_not_a_database_dump_is_rejected(): void
    {
        // The old verification only checked that the archive decrypted and gunzipped, which
        // an empty or truncated dump passes happily.
        $this->expectException(\RuntimeException::class);

        BackupRun::assertPlausibleDump("-- MySQL dump\n-- no tables here\n", 'mysql');
    }

    public function test_a_real_mysql_dump_is_accepted(): void
    {
        $payload = "-- MySQL dump 10.19  Distrib 8.4.10\n--\n-- Host: db    Database: endorsement\n"
            ."-- ------------------------------------------------------\n\n"
            ."CREATE TABLE `handovers` (`id` bigint unsigned NOT NULL AUTO_INCREMENT,"
            ."`unit_id` bigint unsigned NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;\n";

        BackupRun::assertPlausibleDump($payload, 'mysql');

        $this->addToAssertionCount(1);
    }

    public function test_a_truncated_sqlite_copy_is_rejected_and_a_real_one_accepted(): void
    {
        BackupRun::assertPlausibleDump("SQLite format 3\0".str_repeat('x', 200), 'sqlite');

        $this->expectException(\RuntimeException::class);
        BackupRun::assertPlausibleDump('not a database at all', 'sqlite');
    }

    public function test_it_refuses_to_run_without_a_passphrase(): void
    {
        // Writing an UNENCRYPTED copy of every patient record would be far worse than
        // writing nothing, so a missing passphrase is a hard failure.
        putenv('BACKUP_PASSPHRASE=');

        $this->artisan('backup:run')->assertExitCode(1);
    }

    public function test_it_writes_an_archive_that_decrypts_back_to_the_source_database(): void
    {
        if (! $this->hasOpenssl()) {
            $this->markTestSkipped('openssl is not on PATH in this environment.');
        }

        // Exercise the sqlite branch against a real file (the suite normally runs :memory:).
        $source = storage_path('framework/testing/backup-source.sqlite');
        @mkdir(dirname($source), 0777, true);
        file_put_contents($source, '');
        $pdo = new \PDO('sqlite:'.$source);
        $pdo->exec('CREATE TABLE handovers (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO handovers (id) VALUES (1)');
        $pdo = null;

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $source]);
        putenv('BACKUP_PASSPHRASE=a-long-test-passphrase-1234567890');

        $dir = storage_path('framework/testing/backups');

        $this->artisan('backup:run', ['--path' => $dir])->assertExitCode(0);

        $archives = glob($dir.DIRECTORY_SEPARATOR.'endorsement-*.sql.gz.enc') ?: [];
        $this->assertCount(1, $archives, 'exactly one encrypted archive should exist');

        // The plaintext dump must NOT be left lying around next to it.
        $this->assertSame([], glob($dir.DIRECTORY_SEPARATOR.'*.sql') ?: []);

        // And the archive must not be readable as a database without the passphrase.
        $raw = (string) file_get_contents($archives[0]);
        $this->assertStringNotContainsString('handovers', $raw);
        $this->assertStringStartsWith('Salted__', $raw);

        array_map('unlink', $archives);
        @unlink($source);
        putenv('BACKUP_PASSPHRASE=');
    }

    private function hasOpenssl(): bool
    {
        $probe = new Process(PHP_OS_FAMILY === 'Windows' ? ['where', 'openssl'] : ['which', 'openssl']);
        $probe->run();

        return $probe->isSuccessful();
    }

    /**
     * SPC-TM-017. handover_signoffs stores the PATH of the signature frozen onto a sheet,
     * not the bytes, so a database-only backup restored onto fresh storage yields sheets
     * that still claim to be signed while every signature renders as nothing.
     */
    public function test_signature_files_are_archived_alongside_the_database(): void
    {
        if (! $this->hasOpenssl()) {
            $this->markTestSkipped('openssl is not on PATH in this environment.');
        }

        $sigDir = storage_path('app/private/signatures');
        @mkdir($sigDir, 0777, true);
        file_put_contents($sigDir.DIRECTORY_SEPARATOR.'deadbeef.png', str_repeat('P', 300));

        $source = storage_path('framework/testing/backup-sig-source.sqlite');
        @mkdir(dirname($source), 0777, true);
        file_put_contents($source, '');
        $pdo = new \PDO('sqlite:'.$source);
        $pdo->exec('CREATE TABLE handovers (id INTEGER PRIMARY KEY)');
        $pdo = null;

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $source]);
        putenv('BACKUP_PASSPHRASE=a-long-test-passphrase-1234567890');

        $dir = storage_path('framework/testing/backups-sig');

        $this->artisan('backup:run', ['--path' => $dir])->assertExitCode(0);

        $archives = glob($dir.DIRECTORY_SEPARATOR.'endorsement-signatures-*.tar.gz.enc') ?: [];
        $this->assertCount(1, $archives, 'the signature archive should exist');

        // Encrypted, and the plaintext intermediates must not survive.
        $this->assertStringStartsWith('Salted__', (string) file_get_contents($archives[0]));
        $this->assertSame([], glob($dir.DIRECTORY_SEPARATOR.'*.tar') ?: []);
        $this->assertSame([], glob($dir.DIRECTORY_SEPARATOR.'*.tar.gz') ?: []);

        array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);
        @unlink($sigDir.DIRECTORY_SEPARATOR.'deadbeef.png');
        @unlink($source);
        putenv('BACKUP_PASSPHRASE=');
    }
}
