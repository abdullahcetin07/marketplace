<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Disk
    |--------------------------------------------------------------------------
    |
    | S3-compatible object storage. Works unchanged against AWS S3, MinIO
    | (local development), DigitalOcean Spaces and Cloudflare R2 — only the
    | endpoint changes.
    |
    | The application never writes user content to local disk. Local storage
    | does not survive a container restart, cannot be shared between app
    | instances, and turns "scale out" into "lose half the images".
    |
    */

    'default' => env('FILESYSTEM_DISK', 's3'),

    'disks' => [

        /*
        | Public assets: product images, store logos. Fronted by a CDN.
        */
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'eu-central-1'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => true,
            'visibility' => 'public',
            'report' => false,
        ],

        /*
        | Private documents: tax certificates, identity documents, invoices.
        |
        | Separate bucket rather than a prefix on the public one — a bucket
        | policy misconfiguration should not be able to expose identity
        | documents. Reachable only through short-lived signed URLs
        | (marketplace.media.signed_url_ttl).
        */
        's3-private' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'eu-central-1'),
            'bucket' => env('AWS_PRIVATE_BUCKET', env('AWS_BUCKET').'-private'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => true,
            'visibility' => 'private',
            'report' => false,
        ],

        /*
        | Ephemeral scratch space: CSV imports being parsed, generated PDFs
        | awaiting upload. Nothing here is expected to outlive the request or
        | job that created it.
        */
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => true,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
