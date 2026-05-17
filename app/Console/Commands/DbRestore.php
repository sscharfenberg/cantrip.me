<?php

namespace App\Console\Commands;

use App\Services\FormatService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Psr\Log\LoggerInterface;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Restore the configured MySQL/MariaDB database from one of the dumps
 * written by `db:backup`. Strictly manual — there is no schedule entry
 * for this command (restoring without explicit human intent is exactly
 * the kind of thing you never want to automate).
 *
 * Lists every dump in `config('cantrip.db_backup.path')`, lets the
 * user pick interactively, requires a typed confirmation before
 * destroying the current data, then pipes the .sql straight into
 * `mysql` (decompressing first if the dump is a .tar.gz).
 *
 * Credentials reach `mysql` via a 0600 temp file so the password is
 * never visible in `ps` — same pattern as DbBackup.
 */
class DbRestore extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:restore
        {--file= : Restore from a specific file path, skipping the picker}
        {--force : Skip the destructive-operation confirmation prompt}';

    /**
     * @var string
     */
    protected $description = 'Restore the database from a db:backup dump. Interactive — prompts to pick which file.';

    public function __construct(private readonly FormatService $formatService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $log = Log::channel('schedule');
        $start = microtime(true);

        $backupDir = config('cantrip.db_backup.path') ?? storage_path('app/db-backups');

        $connection = config('database.connections.mysql');
        if (! is_array($connection)) {
            $this->error('No mysql connection configured.');
            $log->error('DbRestore: no mysql connection configured — aborting');

            return Command::FAILURE;
        }

        $sourceFile = $this->resolveSourceFile($backupDir);
        if ($sourceFile === null) {
            return Command::FAILURE;
        }

        $this->newLine();
        $this->warn('About to REPLACE every table in database "'.$connection['database'].'"');
        $this->line('  with the contents of '.$sourceFile);
        $this->newLine();
        if (! $this->option('force') && ! confirm('Continue?', default: false)) {
            $this->info('Restore cancelled.');

            return Command::SUCCESS;
        }

        $log->info('DbRestore: starting restore of '.$sourceFile.' into '.$connection['database']);

        try {
            [$sqlFile, $tempDir] = $this->prepareSqlFile($sourceFile, $log);
            if ($sqlFile === null) {
                return Command::FAILURE;
            }

            try {
                if (! $this->runMysqlImport($connection, $sqlFile, $log)) {
                    return Command::FAILURE;
                }
            } finally {
                if ($tempDir !== null) {
                    $this->cleanupTempDir($tempDir, $log);
                }
            }
        } catch (Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());
            $log->error('DbRestore: '.$e::class.' — '.$e->getMessage());

            return Command::FAILURE;
        }

        $elapsed = round(microtime(true) - $start, 2);
        $this->newLine();
        $this->info("Restore complete in {$elapsed}s.");
        $log->info("DbRestore: done in {$elapsed}s");

        return Command::SUCCESS;
    }

    /**
     * Resolve the file to restore from — either the explicit --file
     * argument, or an interactive picker over the contents of the
     * backup directory. Returns null on any user-visible error.
     */
    private function resolveSourceFile(string $backupDir): ?string
    {
        if ($explicit = $this->option('file')) {
            if (! is_string($explicit) || ! is_file($explicit)) {
                $this->error("File not found: {$explicit}");

                return null;
            }

            return $explicit;
        }

        $candidates = $this->listBackups($backupDir);
        if (empty($candidates)) {
            $this->error("No backups found in {$backupDir}.");

            return null;
        }

        $options = [];
        foreach ($candidates as $candidate) {
            $options[$candidate['path']] = $candidate['label'];
        }

        return select(
            label: 'Which backup do you want to restore?',
            options: $options,
            scroll: 10,
        );
    }

    /**
     * Scan the backup directory, return a list of restore candidates
     * sorted newest-first. Each entry has a full `path` and a human
     * `label` suitable for the picker (date, type, size, age).
     *
     * @return list<array{path: string, label: string, date: Carbon}>
     */
    private function listBackups(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = array_merge(
            glob("{$dir}/db-backup-*.sql") ?: [],
            glob("{$dir}/db-backup-*.tar.gz") ?: [],
        );

        $rows = [];
        $today = Carbon::today();
        foreach ($files as $path) {
            if (! preg_match('/db-backup-(\d{4}-\d{2}-\d{2})\.(sql|tar\.gz)$/', basename($path), $m)) {
                continue;
            }
            $date = Carbon::createFromFormat('Y-m-d', $m[1])->startOfDay();
            $size = (int) (filesize($path) ?: 0);
            $age = $this->ageLabel($date, $today);
            $rows[] = [
                'path' => $path,
                'date' => $date,
                'label' => sprintf(
                    '%s (%s) — %s — %s',
                    basename($path),
                    $age,
                    $m[2],
                    $this->formatService->formatBytes($size)
                ),
            ];
        }

        // Newest first — typical restore target.
        usort($rows, fn ($a, $b) => $b['date']->getTimestamp() <=> $a['date']->getTimestamp());

        return $rows;
    }

    /**
     * Given a candidate file, return [$sqlFile, $tempDir]. For a plain
     * .sql, $tempDir is null and $sqlFile is the input as-is. For a
     * .tar.gz, the archive is extracted to a fresh temp dir and the
     * caller is expected to clean it up via cleanupTempDir().
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function prepareSqlFile(string $sourceFile, LoggerInterface $log): array
    {
        if (str_ends_with($sourceFile, '.sql')) {
            return [$sourceFile, null];
        }
        if (! str_ends_with($sourceFile, '.tar.gz')) {
            $this->error("Unrecognised backup format: {$sourceFile}");

            return [null, null];
        }

        $tempDir = sys_get_temp_dir().'/cantrip-restore-'.bin2hex(random_bytes(6));
        if (! mkdir($tempDir, 0700) && ! is_dir($tempDir)) {
            $this->error("Could not create temp directory at {$tempDir}");

            return [null, null];
        }
        $log->debug("DbRestore: extracting archive into {$tempDir}");

        $result = Process::path($tempDir)->timeout(0)->run([
            'tar', '-xzf', $sourceFile,
        ]);

        if (! $result->successful()) {
            $this->error('tar extraction failed: '.trim($result->errorOutput() ?: $result->output()));
            $log->error('DbRestore: tar extraction failed (exit '.$result->exitCode().') — '.trim($result->errorOutput()));
            $this->cleanupTempDir($tempDir, $log);

            return [null, null];
        }

        $extracted = glob("{$tempDir}/db-backup-*.sql") ?: [];
        if (empty($extracted)) {
            $this->error('Archive extracted but no db-backup-*.sql found inside.');
            $this->cleanupTempDir($tempDir, $log);

            return [null, null];
        }

        return [$extracted[0], $tempDir];
    }

    /**
     * Pipe $sqlFile into `mysql` via stdin. Credentials are passed via
     * a 0600 temp file so the password isn't visible in `ps`.
     *
     * @param  array<string, mixed>  $connection
     */
    private function runMysqlImport(array $connection, string $sqlFile, LoggerInterface $log): bool
    {
        $credentialsFile = tempnam(sys_get_temp_dir(), 'cantrip-mysql-restore-');
        if ($credentialsFile === false) {
            $this->error('Could not create temp credentials file.');

            return false;
        }
        chmod($credentialsFile, 0600);
        $password = (string) ($connection['password'] ?? '');
        $username = (string) ($connection['username'] ?? '');
        file_put_contents(
            $credentialsFile,
            "[client]\nuser={$username}\npassword=\"{$password}\"\n"
        );

        $success = false;
        try {
            $cmd = [
                'mysql',
                '--defaults-extra-file='.$credentialsFile,
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? '3306'),
                '--default-character-set=utf8mb4',
                (string) $connection['database'],
            ];

            $handle = fopen($sqlFile, 'r');
            if ($handle === false) {
                $this->error("Could not open {$sqlFile} for reading.");
            } else {
                $log->debug('DbRestore: piping '.basename($sqlFile).' into mysql');

                try {
                    // Stream the file directly to mysql's stdin instead of
                    // buffering it in PHP (dumps can be 100s of MB).
                    $result = Process::input($handle)->timeout(0)->run($cmd);
                } finally {
                    fclose($handle);
                }

                if (! $result->successful()) {
                    $this->error(sprintf(
                        'mysql import failed (exit %d): %s',
                        $result->exitCode(),
                        trim($result->errorOutput() ?: $result->output())
                    ));
                    $log->error(sprintf(
                        'DbRestore: mysql import failed (exit %d) — %s',
                        $result->exitCode(),
                        trim($result->errorOutput() ?: $result->output())
                    ));
                } else {
                    $log->info('DbRestore: imported '.basename($sqlFile).' into '.$connection['database']);
                    $success = true;
                }
            }
        } finally {
            @unlink($credentialsFile);
        }

        return $success;
    }

    /**
     * Remove the extracted .sql file + its temp directory. Called from
     * the finally block of handle() so we never leave a multi-hundred-
     * megabyte dump sitting in /tmp on failure.
     */
    private function cleanupTempDir(string $dir, LoggerInterface $log): void
    {
        foreach (glob("{$dir}/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
        $log->debug("DbRestore: cleaned up temp dir {$dir}");
    }

    /**
     * Human-readable age relative to today ("today" / "1 day ago" / …).
     *
     * Carbon 3's `diffInDays` returns a signed `float` by default, so we
     * cast to int — otherwise the strict equality `$days === 1` would
     * compare `1.0 === 1`, never match, and 1-day-old backups would
     * incorrectly render as "1 days ago" via the fallthrough branch.
     */
    private function ageLabel(Carbon $date, Carbon $today): string
    {
        $days = (int) $date->diffInDays($today);
        if ($days <= 0) {
            return 'today';
        }
        if ($days === 1) {
            return '1 day ago';
        }

        return "{$days} days ago";
    }
}
