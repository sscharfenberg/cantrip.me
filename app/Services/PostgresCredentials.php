<?php

namespace App\Services;

/**
 * How `db:backup` and `db:restore` hand the database's credentials to the
 * Postgres client tools without putting the password on a command line,
 * where `ps` would show it.
 */
final class PostgresCredentials
{
    /**
     * The default connection's config, or null when it is not Postgres.
     *
     * @return array<string, mixed>|null
     */
    public static function connection(): ?array
    {
        $connection = config('database.connections.'.config('database.default'));

        return is_array($connection) && ($connection['driver'] ?? null) === 'pgsql' ? $connection : null;
    }

    /**
     * Host, port, user and database as client-tool arguments.
     *
     * @param  array<string, mixed>  $connection
     * @return list<string>
     */
    public static function connectionArguments(array $connection): array
    {
        return [
            '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($connection['port'] ?? '5432'),
            '--username='.(string) ($connection['username'] ?? ''),
            '--dbname='.(string) $connection['database'],
        ];
    }

    /**
     * Write the password to a 0600 temp file in `.pgpass` format, for the
     * client tool to read through `PGPASSFILE`. The caller deletes it.
     * Returns null when no temp file could be created.
     *
     * @param  array<string, mixed>  $connection
     */
    public static function writePassFile(array $connection): ?string
    {
        $file = tempnam(sys_get_temp_dir(), 'cantrip-pgpass-');
        if ($file === false) {
            return null;
        }
        chmod($file, 0600);

        // `.pgpass` fields are colon-separated; `\` and `:` inside one are
        // escaped with a backslash.
        $escape = fn (string $value): string => addcslashes($value, '\\:');
        file_put_contents($file, '*:*:*:'
            .$escape((string) ($connection['username'] ?? '')).':'
            .$escape((string) ($connection['password'] ?? ''))."\n");

        return $file;
    }
}
