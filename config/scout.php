<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Search Engine
    |--------------------------------------------------------------------------
    |
    | `opensearch` is registered by App\Providers\SearchServiceProvider and
    | implemented by App\Core\Infrastructure\Search\OpenSearchEngine.
    |
    | Set SCOUT_DRIVER=null in the test suite (already done in .env.testing) so
    | tests never reach a cluster.
    |
    */

    'driver' => env('SCOUT_DRIVER', 'opensearch'),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    |
    | Namespaces indexes so staging and production can share a cluster without
    | overwriting each other's documents.
    |
    */

    'prefix' => env('SCOUT_PREFIX', 'mos_'),

    /*
    |--------------------------------------------------------------------------
    | Queue Indexing
    |--------------------------------------------------------------------------
    |
    | ON in every environment except tests. Indexing synchronously puts an
    | HTTP round-trip to OpenSearch inside the user's save request, and makes
    | a cluster hiccup look like a broken save.
    |
    */

    'queue' => [
        'connection' => env('SCOUT_QUEUE_CONNECTION', 'redis'),
        'queue' => env('SCOUT_QUEUE_NAME', 'search'),
    ],

    /*
    | Wait for the transaction to commit before indexing. Without this, a
    | rolled-back save can still leave a document in the index.
    */
    'after_commit' => true,

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    /*
    | Keep soft-deleted records out of the index entirely rather than filtering
    | them at query time.
    */
    'soft_delete' => false,

    'identify' => false,

];
