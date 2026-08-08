<?php

namespace App\Console\Commands;

use App\Support\Instance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Encrypted database backup — the FIRST layer of the encryption-at-rest plan
 * (docs/COMPLIANCE.md), because an unencrypted backup is where health data actually
 * leaks, long before anybody steals a disk.
 *
 * Design rules, each chosen so a backup is still recoverable on the worst day:
 *
 *  - OPENSSL-STANDARD FORMAT. The archive is `openssl enc -aes-256-cbc -pbkdf2 -salt`,
 *    so recovery needs openssl and the passphrase — NOT this application, not PHP, not
 *    Laravel. A backup you can only open with the system you lost is not a backup.
 *  - PASSPHRASE ≠ APP_KEY. `BACKUP_PASSPHRASE` is its own secret, held by the owner
 *    offline. (APP_KEY is still needed to read the encrypted PHI *columns* inside the
 *    dump — keep BOTH in the key-custody runbook.)
 *  - VERIFIES ITSELF. The archive is decrypted and gunzipped to /dev/null before the run
 *    is called a success. A backup nobody has ever restored is a hypothesis.
 *  - NO PHI IN OUTPUT. Console lines and audit rows carry file names, byte counts and
 *    durations only.
 *
 * Run it from cron/Task Scheduler nightly, and copy the result off the machine.
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run
        {--path= : Directory to write the archive to (default: storage/backups)}
        {--keep=14 : How many archives to retain in that directory}';

    protected $description = 'Write an encrypted, verified database backup';

    /** `Y-m-d_His`, as a glob. This is the anchor that stops slug `qch` matching slug `qch-2`. */
    private const STAMP_GLOB = '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]_[0-9][0-9][0-9][0-9][0-9][0-9]';

    public function handle(): int
    {
        $passphrase = (string) env('BACKUP_PASSPHRASE', '');

        if ($passphrase === '') {
            $this->error('BACKUP_PASSPHRASE is not set. Generate a long random passphrase, store it OFFLINE, and set it in the environment.');

            return self::FAILURE;
        }

        if (! $this->hasBinary('openssl')) {
            $this->error('The `openssl` binary is required (it is what makes the archive recoverable without this application).');

            return self::FAILURE;
        }

        $dir = rtrim((string) ($this->option('path') ?: storage_path('backups')), '/\\');
        File::ensureDirectoryExists($dir);

        $slug = Instance::slug();
        $stamp = now()->format('Y-m-d_His');
        $plain = $dir.DIRECTORY_SEPARATOR."endorsement-{$slug}-{$stamp}.sql";
        $archive = $plain.'.gz.enc';

        $started = microtime(true);

        $driver = (string) config('database.connections.'.config('database.default').'.driver');

        try {
            $this->dump($plain);
            $this->compressAndEncrypt($plain, $archive, $passphrase);
            $this->verify($archive, $passphrase, $driver);
        } catch (\Throwable $e) {
            @unlink($plain);
            @unlink($archive);
            // The message may name a path but never a row value.
            $this->error('Backup FAILED: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if (is_file($plain)) {
                // Shred the plaintext dump: it is the one unencrypted copy of everything.
                $this->shred($plain);
            }
        }

        $signatures = $this->backupSignatures($dir, $slug, $stamp, $passphrase);

        $bytes = (int) filesize($archive);
        $seconds = round(microtime(true) - $started, 1);

        $this->writeMeta($archive, $slug, $stamp, $bytes);

        $this->warnAboutLegacyArchives($dir);

        $keep = (int) $this->option('keep');
        $this->prune($dir, $keep, 'endorsement-'.$slug.'-'.self::STAMP_GLOB.'.sql.gz.enc');
        $this->prune($dir, $keep, 'endorsement-'.$slug.'-'.self::STAMP_GLOB.'.sql.gz.enc.meta.json');

        \App\Models\AuditLog::record('backup_created', 'instance='.$slug.' bytes='.$bytes.' seconds='.$seconds, null, null);

        if ($signatures !== null) {
            $this->info("Signature archive written: {$signatures}");
        }

        $this->info("Backup written and verified: {$archive} ({$bytes} bytes, {$seconds}s)");
        $this->line('Copy it off this machine. Restore with:');
        $this->line("  openssl enc -d -aes-256-cbc -pbkdf2 -in <file>.gz.enc | gunzip > restore.sql");

        return self::SUCCESS;
    }

    /**
     * The database dump is not the whole record.
     *
     * `handover_signoffs` stores the PATH of the signature image that was frozen onto a
     * sheet at sign-off, not the bytes. Those files live on disk, outside the SQL dump — so
     * restoring the database onto fresh storage produced sheets that still claimed to be
     * signed while every signature rendered as nothing. The attestation is the point of the
     * document; losing its image silently weakens every historical one.
     *
     * Written as a SEPARATE encrypted archive rather than folded into the SQL one, so the
     * documented restore recipe for the database is unchanged and each artefact can be
     * verified on its own.
     *
     * @return string|null the archive path, or null when there are no signatures yet
     */
    private function backupSignatures(string $dir, string $slug, string $stamp, string $passphrase): ?string
    {
        $source = storage_path('app/private/signatures');

        if (! is_dir($source) || count(glob($source.DIRECTORY_SEPARATOR.'*') ?: []) === 0) {
            return null;
        }

        $tar = $dir.DIRECTORY_SEPARATOR."endorsement-signatures-{$slug}-{$stamp}.tar";
        $gz = $tar.'.gz';
        $archive = $gz.'.enc';

        @unlink($tar);
        @unlink($gz);

        try {
            // PharData rather than shelling out to tar: no external binary, and it is
            // exempt from phar.readonly (which is on in production).
            $phar = new \PharData($tar);
            $phar->buildFromDirectory($source);
            $phar->compress(\Phar::GZ);
            unset($phar);

            if (! is_file($gz)) {
                throw new \RuntimeException('The signature archive was not compressed.');
            }

            $process = new Process(
                ['openssl', 'enc', '-aes-256-cbc', '-pbkdf2', '-salt', '-in', $gz, '-out', $archive, '-pass', 'env:BACKUP_PASSPHRASE'],
                null,
                ['BACKUP_PASSPHRASE' => $passphrase],
                null,
                1800,
            );
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException('Signature archive encryption failed.');
            }
        } finally {
            // Both intermediates are readable copies of staff signatures — a forgeable
            // credential. Neither may be left on disk.
            $this->shred($tar);
            $this->shred($gz);
        }

        $this->prune($dir, (int) $this->option('keep'),
            'endorsement-signatures-'.$slug.'-'.self::STAMP_GLOB.'.tar.gz.enc');

        return $archive;
    }

    /** Dump the default connection. MySQL via mysqldump; SQLite is a file copy. */
    private function dump(string $target): void
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) === 'sqlite') {
            $source = $config['database'];

            if ($source === ':memory:' || ! is_file($source)) {
                throw new \RuntimeException('The SQLite database file does not exist.');
            }

            if (! copy($source, $target)) {
                throw new \RuntimeException('Could not copy the SQLite database file.');
            }

            return;
        }

        if (! $this->hasBinary('mysqldump')) {
            throw new \RuntimeException('The `mysqldump` binary was not found on PATH.');
        }

        $process = new Process(
            self::dumpArgs($config, $target, self::clientIsMariaDb()),
            null,
            // Never in argv: /proc and `ps` expose a command line to every local user.
            ['MYSQL_PWD' => (string) ($config['password'] ?? '')],
            null,
            3600,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            // NEVER the raw stderr: a dump that fails mid-table quotes the offending row,
            // which would put a patient identifier in a log. The numeric MySQL error number
            // is enough to diagnose (2026 = TLS, 1045 = auth, 2002 = cannot connect) and
            // carries no row data. "exited with code 2" alone cost an hour once.
            $errno = self::mysqlErrorNumber($process->getErrorOutput());

            throw new \RuntimeException(
                'mysqldump exited with code '.$process->getExitCode()
                .($errno !== null ? ' (mysql errno '.$errno.')' : '')
                .'. Run `mysqldump --version` and check connectivity from the app container.',
            );
        }
    }

    /** Pull just the numeric error number out of mysqldump's stderr — never the message. */
    public static function mysqlErrorNumber(string $stderr): ?int
    {
        return preg_match('/error:?\s*(\d{4})/i', $stderr, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Build the dump command line.
     *
     * The TLS flags are client-specific and there is NO spelling that works on both:
     * Alpine's `mysql-client` package is MariaDB's client, whose 11.x releases verify the
     * server certificate by default — and MySQL 8.4 auto-generates a self-signed one, so
     * the dump dies with "TLS/SSL error: self-signed certificate in certificate chain".
     * MariaDB wants `--ssl-verify-server-cert=0`; MySQL 8.4 REMOVED that option and wants
     * `--ssl-mode`. Passing the wrong one aborts the client, so we detect the vendor.
     *
     * The connection still uses TLS — only the certificate check is relaxed, matching what
     * Oracle's own client does by default. The hop is a private, internal-only docker
     * network that no other container on the host can reach (asserted in docker/smoke.sh).
     *
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    public static function dumpArgs(array $config, string $target, bool $clientIsMariaDb): array
    {
        return [
            'mysqldump',
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? ''),
            $clientIsMariaDb ? '--ssl-verify-server-cert=0' : '--ssl-mode=PREFERRED',
            // Dumping tablespaces needs the PROCESS privilege, which the application's DB
            // user deliberately does not have. Skipping them keeps least privilege intact —
            // the alternative was granting a server-wide privilege for a backup.
            '--no-tablespaces',
            '--single-transaction',
            '--routines',
            '--triggers',
            '--result-file='.$target,
            (string) ($config['database'] ?? ''),
        ];
    }

    /** True when the `mysqldump` on PATH is MariaDB's rather than Oracle's. */
    private static function clientIsMariaDb(): bool
    {
        $probe = new Process(['mysqldump', '--version']);
        $probe->run();

        return self::versionIsMariaDb($probe->getOutput().$probe->getErrorOutput());
    }

    public static function versionIsMariaDb(string $versionBanner): bool
    {
        return stripos($versionBanner, 'mariadb') !== false;
    }

    /**
     * A backup that decrypts and decompresses is not necessarily a backup.
     *
     * The original check stopped at "it gunzipped", which an empty or truncated dump passes
     * happily — you find out only when you need it. This asserts the payload actually looks
     * like a dump of THIS database.
     */
    public static function assertPlausibleDump(string $payload, string $driver): void
    {
        if (strlen($payload) < 128) {
            throw new \RuntimeException('The archive payload is too small to be a database.');
        }

        if ($driver === 'sqlite') {
            if (! str_starts_with($payload, 'SQLite format 3')) {
                throw new \RuntimeException('The archive payload is not a SQLite database file.');
            }

            return;
        }

        if (stripos($payload, 'CREATE TABLE') === false) {
            throw new \RuntimeException('The archive payload contains no CREATE TABLE statement.');
        }

        // `handovers` is the table the whole system exists to protect. A dump without it is
        // not a backup of this application, whatever else it contains.
        if (stripos($payload, 'handovers') === false) {
            throw new \RuntimeException('The archive payload does not contain the handovers table.');
        }
    }

    private function compressAndEncrypt(string $plain, string $archive, string $passphrase): void
    {
        $gz = $plain.'.gz';
        $in = fopen($plain, 'rb');
        $out = gzopen($gz, 'wb9');

        if ($in === false || $out === false) {
            throw new \RuntimeException('Could not open the dump for compression.');
        }

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1_048_576));
        }

        fclose($in);
        gzclose($out);

        $process = new Process(
            ['openssl', 'enc', '-aes-256-cbc', '-pbkdf2', '-salt', '-in', $gz, '-out', $archive, '-pass', 'env:BACKUP_PASSPHRASE'],
            null,
            ['BACKUP_PASSPHRASE' => $passphrase],
            null,
            1800,
        );
        $process->run();

        // The .gz is a complete, readable copy of every clinical record. Unlinking alone
        // leaves those blocks on disk; shred it the same way as the plaintext dump.
        $this->shred($gz);

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('openssl encryption failed with code '.$process->getExitCode().'.');
        }
    }

    /** Decrypt + decompress the archive we just wrote. An unverified backup is a guess. */
    private function verify(string $archive, string $passphrase, string $driver): void
    {
        $process = new Process(
            ['openssl', 'enc', '-d', '-aes-256-cbc', '-pbkdf2', '-in', $archive, '-pass', 'env:BACKUP_PASSPHRASE'],
            null,
            ['BACKUP_PASSPHRASE' => $passphrase],
            null,
            1800,
        );
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('The archive could not be decrypted after writing.');
        }

        $decompressed = @gzdecode($process->getOutput());

        if ($decompressed === false || $decompressed === '') {
            throw new \RuntimeException('The archive decrypted but did not decompress.');
        }

        self::assertPlausibleDump($decompressed, $driver);
    }

    /**
     * Overwrite before unlinking — the plaintext dump is every record in one file.
     *
     * The WHOLE file, not the first megabyte: the original capped the overwrite at 1 MiB,
     * so on any real census the overwhelming majority of the clinical record was unlinked
     * but left intact on disk, recoverable by anyone who could read the raw volume. Written
     * in chunks so a large dump does not have to be materialised in memory.
     */
    private function shred(string $path): void
    {
        $size = (int) @filesize($path);

        if ($size > 0 && ($handle = @fopen($path, 'r+')) !== false) {
            $chunk = str_repeat("\0", 1_048_576);

            for ($written = 0; $written < $size; $written += 1_048_576) {
                fwrite($handle, substr($chunk, 0, (int) min(1_048_576, $size - $written)));
            }

            fflush($handle);
            fclose($handle);
        }

        @unlink($path);
    }

    private function prune(string $dir, int $keep, ?string $pattern = null): void
    {
        $pattern ??= 'endorsement-'.Instance::slug().'-'.self::STAMP_GLOB.'.sql.gz.enc';

        if ($keep < 1) {
            return;
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.$pattern) ?: [];
        rsort($files);

        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
            $this->line('Pruned old archive: '.basename($old));
        }
    }

    /**
     * The direct answer to "which APP_KEY opens this archive" — the question an operator
     * standing in front of a bucket cannot answer today, because the only in-band identity is
     * the ciphertext and reading it requires already knowing whose it is. Plaintext BY DESIGN:
     * it must be readable without the passphrase. No secret, no path outside $dir, no PHI.
     */
    private function writeMeta(string $archive, string $slug, string $stamp, int $bytes): void
    {
        $meta = [
            'slug' => $slug,
            'stamp' => $stamp,
            'bytes' => $bytes,
            'app_key_fingerprint' => Instance::keyFingerprint(),
            'hostname' => gethostname() ?: 'unknown',
        ];

        file_put_contents(
            $archive.'.meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
    }

    /**
     * Finding 4: slugging the filename means the fourteen archives the live deployment wrote
     * before this change no longer match any glob, so they are never pruned automatically —
     * and never eaten by another customer's retention either. Warn, by count; never widen the
     * prune pattern back to catch them, which is finding 3 restored under a new name.
     */
    private function warnAboutLegacyArchives(string $dir): void
    {
        $legacy = glob($dir.DIRECTORY_SEPARATOR.'endorsement-'.self::STAMP_GLOB.'.sql.gz.enc') ?: [];

        if ($legacy === []) {
            return;
        }

        $count = count($legacy);
        $this->warn(
            "{$count} archive(s) in this directory predate the instance slug and are no longer "
            .'pruned automatically. They belong to this instance. Remove or rename them once you '
            .'have confirmed a slugged archive restores: docs/RUNBOOK-BACKUP.md.'
        );
    }

    private function hasBinary(string $name): bool
    {
        $probe = new Process(PHP_OS_FAMILY === 'Windows' ? ['where', $name] : ['which', $name]);
        $probe->run();

        return $probe->isSuccessful();
    }
}
