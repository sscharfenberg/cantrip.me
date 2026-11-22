<?php

namespace App\Console\Commands;

use App\Services\FormatService;
use App\Services\PostgresCredentials;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Psr\Log\LoggerInterface;

/**
 * Dump the configured Postgres database to a daily file and prune dumps
 * beyond the retention window.
 *
 * Layout under `config('cantrip.db_backup.path')`: one
 * `db-backup-YYYY-MM-DD.dump` per day, in pg_dump's custom format —
 * compressed, and read back by `db:restore` through pg_restore.
 *
 * Uses `pg_dump` directly via the Process facade — much faster than
 * pulling rows through Eloquent. Credentials reach it through a 0600
 * password file named in `PGPASSFILE`, so the password never appears in
 * `ps`.
 *
 * Designed to run unattended via the scheduler. Detailed progress goes
 * to the `schedule` log channel at info/debug level; failures both log
 * an error AND exit non-zero so the scheduler surfaces the failure.
 */
class DbBackup extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:backup
        {--retention-days= : Override the retention window for this run (default from config)}';

    /**
     * @var string
     */
    protected $description = 'Dump the database via pg_dump, prune dumps beyond the retention window.';

    public function __construct(private readonly FormatService $formatService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $log = Log::channel('schedule');
        $start = microtime(true);

        $retentionDays = (int) ($this->option('retention-days') ?? config('cantrip.db_backup.retention_days', 7));
        // Fallback to the conventional path so a stale `config:cache`
        // (config file uploaded but cache not refreshed) doesn't crash
        // the command with a null directory.
        $backupDir = config('cantrip.db_backup.path') ?? storage_path('app/db-backups');
        $today = Carbon::today()->format('Y-m-d');
        $todayFile = "{$backupDir}/db-backup-{$today}.dump";

        $log->info("DbBackup: starting (retention {$retentionDays} days, path {$backupDir})");

        if (! $this->ensureBackupDir($backupDir, $log)) {
            return Command::FAILURE;
        }

        $connection = PostgresCredentials::connection();
        if ($connection === null) {
            $log->error('DbBackup: the default connection is not Postgres — aborting');
            $this->error('The default database connection is not Postgres.');

            return Command::FAILURE;
        }

        if (! $this->runPgDump($connection, $todayFile, $log)) {
            return Command::FAILURE;
        }

        $this->pruneOldBackups($backupDir, $retentionDays, $log);

        $elapsed = round(microtime(true) - $start, 2);
        $log->info("DbBackup: done in {$elapsed}s");

        return Command::SUCCESS;
    }

    /**
     * Make sure the backup directory exists and is writable.
     */
    private function ensureBackupDir(string $dir, LoggerInterface $log): bool
    {
        if (! is_dir($dir)) {
            if (! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                $log->error("DbBackup: could not create backup directory at {$dir}");

                return false;
            }
            $log->debug("DbBackup: created backup directory at {$dir}");
        }
        if (! is_writable($dir)) {
            $log->error("DbBackup: backup directory {$dir} is not writable");

            return false;
        }

        return true;
    }

    /**
     * Run `pg_dump` and write to $outFile. Returns true on success.
     *
     * @param  array<string, mixed>  $connection
     */
    private function runPgDump(array $connection, string $outFile, LoggerInterface $log): bool
    {
        $passFile = PostgresCredentials::writePassFile($connection);
        if ($passFile === null) {
            $log->error('DbBackup: could not create temp password file');

            return false;
        }

        try {
            $cmd = [
                'pg_dump',
                '--format=custom',
                '--no-password',
                ...PostgresCredentials::connectionArguments($connection),
                '--file='.$outFile,
            ];

            $log->debug('DbBackup: running pg_dump for database '.$connection['database']);

            // No timeout — large dumps can take a while; the scheduler
            // already wraps this command in its own monitoring.
            $result = Process::env(['PGPASSFILE' => $passFile])->timeout(0)->run($cmd);

            if (! $result->successful()) {
                $log->error(sprintf(
                    'DbBackup: pg_dump failed (exit %d): %s',
                    $result->exitCode(),
                    trim($result->errorOutput() ?: $result->output())
                ));
                @unlink($outFile);

                return false;
            }

            // Tighten the dump's permissions — contains potentially
            // sensitive data, no world read.
            @chmod($outFile, 0640);

            $bytes = (int) (@filesize($outFile) ?: 0);
            $log->info('DbBackup: wrote '.$this->formatService->formatBytes($bytes).' to '.basename($outFile));

            return true;
        } finally {
            @unlink($passFile);
        }
    }

    /**
     * Delete any dump whose dated filename is older than $retentionDays
     * before today.
     */
    private function pruneOldBackups(string $dir, int $retentionDays, LoggerInterface $log): void
    {
        $cutoff = Carbon::today()->subDays($retentionDays);

        foreach (glob("{$dir}/db-backup-*.dump") ?: [] as $file) {
            if (! preg_match('/db-backup-(\d{4}-\d{2}-\d{2})\.dump$/', basename($file), $m)) {
                continue;
            }
            $date = Carbon::createFromFormat('Y-m-d', $m[1])->startOfDay();
            if ($date->lt($cutoff)) {
                @unlink($file);
                $log->info('DbBackup: pruned '.basename($file)." (older than {$retentionDays} days)");
            }
        }
    }
}
