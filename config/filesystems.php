<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],
        'set' => [
            'driver' => 'local',
            'root' => storage_path('app/set'),
            'url' => env('APP_URL').'/set',
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ],
        'symbol' => [
            'driver' => 'local',
            'root' => storage_path('app/symbol'),
            'url' => env('APP_URL').'/symbol',
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ],
        'art-crops' => [
            'driver' => 'local',
            'root' => storage_path('app/art-crops'),
            'url' => env('APP_URL').'/art-crops',
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ],
        'card-images' => [
            'driver' => 'local',
            'root' => storage_path('app/card-images'),
            'url' => env('APP_URL').'/card-images',
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ],
        'tmp' => [
            'driver' => 'local',
            'root' => storage_path('app/tmp'),
            'visibility' => 'private',
            'throw' => true,
            'permissions' => [
                'file' => [
                    'private' => 0660,
                ],
                'dir' => [
                    'private' => 0770,
                ],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
        public_path('set') => storage_path('app/set'),
        public_path('symbol') => storage_path('app/symbol'),
        public_path('art-crops') => storage_path('app/art-crops'),
        public_path('card-images') => storage_path('app/card-images'),
    ],

];
