<?php

return [

    /*
    |--------------------------------------------------------------------------
    | scryfall settings
    |--------------------------------------------------------------------------
    |
    | basic settings for scryfall imports.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | CSV import settings
    |--------------------------------------------------------------------------
    */
    'csv_upload' => [
        'max_bytes' => 2 * 1024 * 1024, // 2 MB
        'allowed_types' => ['.csv'],
    ],

    /*
    |--------------------------------------------------------------------------
    | scryfall settings
    |--------------------------------------------------------------------------
    |
    | basic settings for scryfall imports.
    |
    */
    'scryfall' => [
        'header' => [
            // Scryfall enforces a User-Agent naming the app as `Name/version`
            // (blog post of 2026-10-14); the comment adds where to reach us.
            'User-Agent' => 'cantrip.me/'.ltrim(config('app.version'), 'v')
                .' (+'.config('app.url').'; '.config('app.contact').')',
            'Accept' => 'application/json',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database backup settings
    |--------------------------------------------------------------------------
    |
    | Drives the `db:backup` artisan command (scheduled daily). Backups
    | land in `path`; anything older than `retention_days` is pruned.
    | Today's dump stays as a plain .sql file; every previous day's dump
    | gets tar.gz'd on the next run so the directory holds at most one
    | uncompressed file.
    |
    */
    'db_backup' => [
        'retention_days' => env('DB_BACKUP_RETENTION_DAYS', 7),
        'path' => storage_path('app/db-backups'),
    ],

];
