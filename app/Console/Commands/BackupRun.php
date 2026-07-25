<?php

namespace App\Console\Commands;

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

        $stamp = now()->format('Y-m-d_His');
        $plain = $dir.DIRECTORY_SEPARATOR."endorsement-{$stamp}.sql";
        $archive = $plain.'.gz.enc';

        $started = microtime(true);

        try {
            $this->dump($plain);
            $this->compressAndEncrypt($plain, $archive, $passphrase);
            $this->verify($archive, $passphrase);
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

        $bytes = (int) filesize($archive);
        $seconds = round(microtime(true) - $started, 1);

        $this->prune($dir, (int) $this->option('keep'));

        \App\Models\AuditLog::record('backup_created', 'bytes='.$bytes.' seconds='.$seconds, null, null);

        $this->info("Backup written and verified: {$archive} ({$bytes} bytes, {$seconds}s)");
        $this->line('Copy it off this machine. Restore with:');
        $this->line("  openssl enc -d -aes-256-cbc -pbkdf2 -in <file>.gz.enc | gunzip > restore.sql");

        return self::SUCCESS;
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

        $process = new Process([
            'mysqldump',
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? ''),
            '--single-transaction',
            '--routines',
            '--triggers',
            '--result-file='.$target,
            $config['database'] ?? '',
        ], null, ['MYSQL_PWD' => (string) ($config['password'] ?? '')], null, 3600);

        $process->run();

        if (! $process->isSuccessful()) {
            // Never echo the process output verbatim — a failing dump can quote row data.
            throw new \RuntimeException('mysqldump exited with code '.$process->getExitCode().'.');
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

        @unlink($gz);

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('openssl encryption failed with code '.$process->getExitCode().'.');
        }
    }

    /** Decrypt + decompress the archive we just wrote. An unverified backup is a guess. */
    private function verify(string $archive, string $passphrase): void
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
    }

    /** Overwrite before unlinking — the plaintext dump is every record in one file. */
    private function shred(string $path): void
    {
        $size = (int) @filesize($path);

        if ($size > 0 && ($handle = @fopen($path, 'r+')) !== false) {
            fwrite($handle, str_repeat("\0", min($size, 1_048_576)));
            fclose($handle);
        }

        @unlink($path);
    }

    private function prune(string $dir, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.'endorsement-*.sql.gz.enc') ?: [];
        rsort($files);

        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
            $this->line('Pruned old archive: '.basename($old));
        }
    }

    private function hasBinary(string $name): bool
    {
        $probe = new Process(PHP_OS_FAMILY === 'Windows' ? ['where', $name] : ['which', $name]);
        $probe->run();

        return $probe->isSuccessful();
    }
}
