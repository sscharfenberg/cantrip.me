<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `db:backup` and `db:restore` drive the Postgres client tools; the tools
 * themselves are faked here, so what is pinned is how they are called —
 * above all that the password travels in a password file, never on the
 * command line, and that the file is gone afterwards.
 */
class DbBackupCommandTest extends TestCase
{
    private string $backupDir;

    /** @var list<PendingProcess> */
    private array $ran = [];

    /** Contents of the password file each process was pointed at, read while it ran. */
    private array $passFiles = [];

    private int $exitCode = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDir = sys_get_temp_dir().'/cantrip-backup-test-'.bin2hex(random_bytes(4));
        mkdir($this->backupDir);

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => 'db.test',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.database' => 'cantrip_test',
            'database.connections.pgsql.username' => 'cantrip',
            'database.connections.pgsql.password' => 'p:a\\ss',
            'cantrip.db_backup.path' => $this->backupDir,
            'logging.channels.schedule' => ['driver' => 'monolog', 'handler' => NullHandler::class],
        ]);

        Process::fake(function (PendingProcess $process) {
            $this->ran[] = $process;
            $this->passFiles[] = file_get_contents($process->environment['PGPASSFILE']);

            return Process::result(exitCode: $this->exitCode, errorOutput: $this->exitCode === 0 ? '' : 'connection refused');
        });
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->backupDir}/*") ?: []);
        rmdir($this->backupDir);

        parent::tearDown();
    }

    #[Test]
    public function the_backup_passes_the_password_in_a_file_and_removes_it(): void
    {
        $this->artisan('db:backup')->assertSuccessful();

        $this->assertCount(1, $this->ran);
        $command = $this->ran[0]->command;
        $this->assertSame([
            'pg_dump',
            '--format=custom',
            '--no-password',
            '--host=db.test',
            '--port=5432',
            '--username=cantrip',
            '--dbname=cantrip_test',
            "--file={$this->backupDir}/db-backup-".Carbon::today()->format('Y-m-d').'.dump',
        ], $command);
        $this->assertStringNotContainsString('p:a', implode(' ', $command));
        $this->assertSame("*:*:*:cantrip:p\\:a\\\\ss\n", $this->passFiles[0], 'colon and backslash escaped as .pgpass expects');
        $this->assertFileDoesNotExist($this->ran[0]->environment['PGPASSFILE']);
    }

    #[Test]
    public function the_backup_prunes_dumps_beyond_the_retention_window(): void
    {
        $old = $this->dumpFrom(8);
        $kept = $this->dumpFrom(7);
        $unrelated = "{$this->backupDir}/notes.txt";
        touch($unrelated);

        $this->artisan('db:backup', ['--retention-days' => 7])->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($kept);
        $this->assertFileExists($unrelated);
    }

    #[Test]
    public function a_failed_dump_fails_the_command_and_prunes_nothing(): void
    {
        $this->exitCode = 1;
        $old = $this->dumpFrom(30);

        $this->artisan('db:backup')->assertFailed();

        $this->assertFileExists($old);
        $this->assertFileDoesNotExist($this->ran[0]->environment['PGPASSFILE']);
    }

    #[Test]
    public function neither_command_runs_against_another_driver(): void
    {
        config(['database.default' => 'sqlite']);
        $dump = $this->dumpFrom(1);

        $this->artisan('db:backup')->assertFailed();
        $this->artisan('db:restore', ['--file' => $dump, '--force' => true])->assertFailed();

        Process::assertNothingRan();
    }

    #[Test]
    public function the_restore_replaces_the_dumped_tables_in_one_transaction(): void
    {
        $dump = $this->dumpFrom(1);

        $this->artisan('db:restore', ['--file' => $dump, '--force' => true])->assertSuccessful();

        $this->assertSame([
            'pg_restore',
            '--no-password',
            '--host=db.test',
            '--port=5432',
            '--username=cantrip',
            '--dbname=cantrip_test',
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-acl',
            '--single-transaction',
            '--exit-on-error',
            $dump,
        ], $this->ran[0]->command);
        $this->assertSame("*:*:*:cantrip:p\\:a\\\\ss\n", $this->passFiles[0]);
        $this->assertFileDoesNotExist($this->ran[0]->environment['PGPASSFILE']);
    }

    #[Test]
    public function a_failed_restore_fails_the_command(): void
    {
        $this->exitCode = 1;

        $this->artisan('db:restore', ['--file' => $this->dumpFrom(1), '--force' => true])->assertFailed();
    }

    private function dumpFrom(int $daysAgo): string
    {
        $file = "{$this->backupDir}/db-backup-".Carbon::today()->subDays($daysAgo)->format('Y-m-d').'.dump';
        touch($file);

        return $file;
    }
}
