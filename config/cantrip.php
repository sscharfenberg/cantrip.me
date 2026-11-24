<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    | App\Http\Middleware\ContentSecurityPolicy sends the policy on every
    | response: report-only (violations show in the browser console, nothing
    | breaks) until `enforce` is set, then enforced.
    |
    */
    'csp' => [
        'enforce' => (bool) env('CSP_ENFORCE', false),
    ],

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
    | Drives the `db:backup` artisan command (scheduled daily). One
    | compressed pg_dump file per day lands in `path`; anything older than
    | `retention_days` is pruned. `db:restore` reads them back.
    |
    */
    'db_backup' => [
        'retention_days' => env('DB_BACKUP_RETENTION_DAYS', 7),
        'path' => storage_path('app/db-backups'),
    ],

];
