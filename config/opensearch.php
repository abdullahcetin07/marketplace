<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | Consumed by App\Providers\SearchServiceProvider when building the
    | OpenSearch client.
    |
    */

    'hosts' => array_map(
        static fn (string $host): string => trim($host),
        explode(',', (string) env('OPENSEARCH_HOST', 'opensearch')),
    ),

    'port' => (int) env('OPENSEARCH_PORT', 9200),

    'scheme' => env('OPENSEARCH_SCHEME', 'http'),

    'auth' => [
        'username' => env('OPENSEARCH_USER'),
        'password' => env('OPENSEARCH_PASSWORD'),
    ],

    /*
    | Verification is off only for the local self-signed cluster. It MUST be
    | true in production — an unverified TLS connection to the search cluster
    | is an unauthenticated one in practice.
    */
    'ssl_verification' => (bool) env('OPENSEARCH_SSL_VERIFY', true),

    'retries' => (int) env('OPENSEARCH_RETRIES', 2),

    'connection_timeout' => (int) env('OPENSEARCH_CONNECTION_TIMEOUT', 5),

    'timeout' => (int) env('OPENSEARCH_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Index Defaults
    |--------------------------------------------------------------------------
    |
    | Applied when the engine auto-creates an index. Single shard is correct
    | for the expected corpus size; raise it deliberately, with measurement,
    | rather than by default — over-sharding is the most common cause of a slow
    | small cluster.
    |
    */

    'index_settings' => [
        'number_of_shards' => (int) env('OPENSEARCH_SHARDS', 1),
        'number_of_replicas' => (int) env('OPENSEARCH_REPLICAS', 1),
        'refresh_interval' => env('OPENSEARCH_REFRESH_INTERVAL', '5s'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Turkish Analysis
    |--------------------------------------------------------------------------
    |
    | Turkish needs more than the default analyser to search correctly:
    |
    |  * `apostrophe` strips the suffix after an apostrophe, so "Ankara'dan"
    |    matches "Ankara".
    |  * Lowercasing must be Turkish-aware — the dotted/dotless i means a
    |    plain lowercase filter turns "İSTANBUL" into something that will not
    |    match "istanbul".
    |  * `turkish_stem` handles the agglutinative suffixes that would otherwise
    |    make every inflected form a separate term.
    |
    | Without this block, Turkish search results are visibly wrong.
    |
    */

    'analysis' => [
        'filter' => [
            'turkish_stop' => [
                'type' => 'stop',
                'stopwords' => '_turkish_',
            ],
            'turkish_lowercase' => [
                'type' => 'lowercase',
                'language' => 'turkish',
            ],
            'turkish_stemmer' => [
                'type' => 'stemmer',
                'language' => 'turkish',
            ],
        ],
        'analyzer' => [
            'turkish_analyzer' => [
                'tokenizer' => 'standard',
                'filter' => [
                    'apostrophe',
                    'turkish_lowercase',
                    'turkish_stop',
                    'turkish_stemmer',
                    'asciifolding',
                ],
            ],
            /*
            | Search-as-you-type. Separate analyser because edge n-grams must
            | be applied at index time only — applying them at query time too
            | would match every prefix of every query term.
            */
            'turkish_autocomplete' => [
                'tokenizer' => 'standard',
                'filter' => [
                    'apostrophe',
                    'turkish_lowercase',
                    'asciifolding',
                    'edge_ngram_filter',
                ],
            ],
        ],
    ],

];
