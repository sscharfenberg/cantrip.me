<?php

namespace App\Console\Commands;

use App\Services\FormatService;
use App\Services\PostgresCredentials;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Psr\Log\LoggerInterface;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Restore the configured Postgres database from one of the dumps written
 * by `db:backup`. Strictly manual — there is no schedule entry for this
 * command (restoring without explicit human intent is exactly the kind of
 * thing you never want to automate).
 *
 * Lists every dump in `config('cantrip.db_backup.path')`, lets the user
 * pick interactively, requires a confirmation before destroying the
 * current data, then runs `pg_restore` in a single transaction: every
 * table in the dump is dropped and recreated, and a failure anywhere
 * leaves the database as it was.
 *
 * Credentials reach `pg_restore` via a 0600 password file so the
 * password is never visible in `ps` — same pattern as DbBackup.
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

        $connection = PostgresCredentials::connection();
        if ($connection === null) {
            $this->error('The default database connection is not Postgres.');
            $log->error('DbRestore: the default connection is not Postgres — aborting');

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
            if (! $this->runPgRestore($connection, $sourceFile, $log)) {
                return Command::FAILURE;
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

        $files = glob("{$dir}/db-backup-*.dump") ?: [];

        $rows = [];
        $today = Carbon::today();
        foreach ($files as $path) {
            if (! preg_match('/db-backup-(\d{4}-\d{2}-\d{2})\.dump$/', basename($path), $m)) {
                continue;
            }
            $date = Carbon::createFromFormat('Y-m-d', $m[1])->startOfDay();
            $size = (int) (filesize($path) ?: 0);
            $age = $this->ageLabel($date, $today);
            $rows[] = [
                'path' => $path,
                'date' => $date,
                'label' => sprintf(
                    '%s (%s) — %s',
                    basename($path),
                    $age,
                    $this->formatService->formatBytes($size)
                ),
            ];
        }

        // Newest first — typical restore target.
        usort($rows, fn ($a, $b) => $b['date']->getTimestamp() <=> $a['date']->getTimestamp());

        return $rows;
    }

    /**
     * Run `pg_restore` on $dumpFile. Returns true on success.
     *
     * `--clean --if-exists` drops each object of the dump before
     * recreating it; `--single-transaction` makes the whole restore one
     * transaction, so an error rolls it all back; `--no-owner --no-acl`
     * leave ownership to the role running it, so a dump of one database
     * restores into another (production's into staging).
     *
     * @param  array<string, mixed>  $connection
     */
    private function runPgRestore(array $connection, string $dumpFile, LoggerInterface $log): bool
    {
        $passFile = PostgresCredentials::writePassFile($connection);
        if ($passFile === null) {
            $this->error('Could not create temp password file.');

            return false;
        }

        try {
            $cmd = [
                'pg_restore',
                '--no-password',
                ...PostgresCredentials::connectionArguments($connection),
                '--clean',
                '--if-exists',
                '--no-owner',
                '--no-acl',
                '--single-transaction',
                '--exit-on-error',
                $dumpFile,
            ];

            $log->debug('DbRestore: running pg_restore of '.basename($dumpFile));
            $result = Process::env(['PGPASSFILE' => $passFile])->timeout(0)->run($cmd);

            if (! $result->successful()) {
                $message = sprintf(
                    'pg_restore failed (exit %d): %s',
                    $result->exitCode(),
                    trim($result->errorOutput() ?: $result->output())
                );
                $this->error($message);
                $log->error('DbRestore: '.$message);

                return false;
            }

            $log->info('DbRestore: restored '.basename($dumpFile).' into '.$connection['database']);

            return true;
        } finally {
            @unlink($passFile);
        }
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
