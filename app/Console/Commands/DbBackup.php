<?php

namespace App\Console\Commands;

use App\Services\FormatService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Psr\Log\LoggerInterface;

/**
 * Dump the configured MySQL/MariaDB database to a daily .sql file and
 * rotate older dumps to .tar.gz, pruning anything beyond the retention
 * window.
 *
 * Layout under `config('cantrip.db_backup.path')`:
 *   - db-backup-YYYY-MM-DD.sql        ← today's dump (uncompressed)
 *   - db-backup-YYYY-MM-DD.tar.gz     ← every previous day inside the window
 *   - (anything older is deleted on each run)
 *
 * Uses `mysqldump` directly via the Process facade — much faster than
 * pulling rows through Eloquent. Credentials are passed via a temp file
 * with `--defaults-extra-file` so the password never appears in `ps`.
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
    protected $description = 'Dump the database via mysqldump, compress older dumps to .tar.gz, prune anything beyond the retention window.';

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
        $todayFile = "{$backupDir}/db-backup-{$today}.sql";

        $log->info("DbBackup: starting (retention {$retentionDays} days, path {$backupDir})");

        if (! $this->ensureBackupDir($backupDir, $log)) {
            return Command::FAILURE;
        }

        $connection = config('database.connections.mysql');
        if (! is_array($connection)) {
            $log->error('DbBackup: no mysql connection configured — aborting');
            $this->error('No mysql connection configured.');

            return Command::FAILURE;
        }

        if (! $this->runMysqldump($connection, $todayFile, $log)) {
            return Command::FAILURE;
        }

        $this->compressOlderBackups($backupDir, $todayFile, $log);
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
     * Run `mysqldump` and write to $outFile. Returns true on success.
     *
     * @param  array<string, mixed>  $connection
     */
    private function runMysqldump(array $connection, string $outFile, LoggerInterface $log): bool
    {
        // Pass credentials via a temp file so the password is never
        // visible in `ps`/`/proc/<pid>/cmdline`. The file gets a 0600
        // mode and is unlinked in the finally{} block below.
        $credentialsFile = tempnam(sys_get_temp_dir(), 'cantrip-mysqldump-');
        if ($credentialsFile === false) {
            $log->error('DbBackup: could not create temp credentials file');

            return false;
        }
        chmod($credentialsFile, 0600);
        $password = (string) ($connection['password'] ?? '');
        $username = (string) ($connection['username'] ?? '');
        file_put_contents(
            $credentialsFile,
            "[client]\nuser={$username}\npassword=\"{$password}\"\n"
        );

        try {
            $cmd = [
                'mysqldump',
                '--defaults-extra-file='.$credentialsFile,
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? '3306'),
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--default-character-set=utf8mb4',
                '--result-file='.$outFile,
                (string) $connection['database'],
            ];

            $log->debug('DbBackup: running mysqldump for database '.$connection['database']);

            // No timeout — large dumps can take a while; the scheduler
            // already wraps this command in its own monitoring.
            $result = Process::timeout(0)->run($cmd);

            if (! $result->successful()) {
                $log->error(sprintf(
                    'DbBackup: mysqldump failed (exit %d): %s',
                    $result->exitCode(),
                    trim($result->errorOutput() ?: $result->output())
                ));
                @unlink($outFile);

                return false;
            }

            // Tighten the dump's permissions — contains potentially
            // sensitive data, no world read.
            @chmod($outFile, 0640);

            $bytes = (int) (filesize($outFile) ?: 0);
            $log->info('DbBackup: wrote '.$this->formatService->formatBytes($bytes).' to '.basename($outFile));

            return true;
        } finally {
            @unlink($credentialsFile);
        }
    }

    /**
     * Compress every db-backup-*.sql file that is NOT today's dump into
     * a sibling .tar.gz, then delete the .sql original.
     *
     * Uses the local `tar` binary — universally available on Linux,
     * cheap to invoke. If `tar` is missing or fails for one file, the
     * .sql is left in place and we move on to the next.
     */
    private function compressOlderBackups(string $dir, string $skipFile, LoggerInterface $log): void
    {
        $sqlFiles = glob("{$dir}/db-backup-*.sql") ?: [];
        foreach ($sqlFiles as $file) {
            if ($file === $skipFile) {
                continue;
            }

            $basename = basename($file, '.sql');
            $archive = "{$dir}/{$basename}.tar.gz";

            if (file_exists($archive)) {
                // Compressed copy already present from a prior run —
                // drop the dangling .sql and move on.
                $log->debug("DbBackup: archive {$basename}.tar.gz already exists, removing stale .sql");
                @unlink($file);

                continue;
            }

            $result = Process::path($dir)->timeout(0)->run([
                'tar', '-czf', "{$basename}.tar.gz", "{$basename}.sql",
            ]);

            if ($result->successful()) {
                @chmod($archive, 0640);
                @unlink($file);
                $log->info("DbBackup: compressed {$basename}.sql → {$basename}.tar.gz");
            } else {
                $log->error(sprintf(
                    'DbBackup: failed to compress %s (exit %d): %s',
                    $file,
                    $result->exitCode(),
                    trim($result->errorOutput() ?: $result->output())
                ));
            }
        }
    }

    /**
     * Delete any backup file (sql or tar.gz) whose dated filename is
     * older than $retentionDays before today.
     */
    private function pruneOldBackups(string $dir, int $retentionDays, LoggerInterface $log): void
    {
        $cutoff = Carbon::today()->subDays($retentionDays);
        $patterns = ["{$dir}/db-backup-*.sql", "{$dir}/db-backup-*.tar.gz"];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (! preg_match('/db-backup-(\d{4}-\d{2}-\d{2})\.(?:sql|tar\.gz)$/', basename($file), $m)) {
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
}
