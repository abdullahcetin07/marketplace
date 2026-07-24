<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Infrastructure\Search\OpenSearchEngine;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use OpenSearch\Client;
use OpenSearch\ClientBuilder;

/**
 * Registers the OpenSearch client and the `opensearch` Scout driver.
 *
 * @see App\Core\Infrastructure\Search\OpenSearchEngine
 * @see config/opensearch.php
 * @see docs/search.md
 */
final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, static function (): Client {
            $config = (array) config('opensearch');

            $hosts = array_map(
                static fn (string $host): string => sprintf(
                    '%s://%s:%d',
                    $config['scheme'],
                    $host,
                    $config['port'],
                ),
                (array) $config['hosts'],
            );

            $builder = ClientBuilder::create()
                ->setHosts($hosts)
                ->setRetries((int) $config['retries'])
                ->setConnectionParams([
                    'client' => [
                        'timeout' => (int) $config['timeout'],
                        'connect_timeout' => (int) $config['connection_timeout'],
                    ],
                ])
                ->setSSLVerification((bool) $config['ssl_verification']);

            if (filled($config['auth']['username'] ?? null)) {
                $builder->setBasicAuthentication(
                    (string) $config['auth']['username'],
                    (string) ($config['auth']['password'] ?? ''),
                );
            }

            return $builder->build();
        });
    }

    public function boot(): void
    {
        /*
        | Resolved lazily: binding the engine eagerly would open a connection
        | to the cluster on every request, including the ones that never
        | search, and would make `artisan` unusable when the cluster is down.
        */
        $this->app->make(EngineManager::class)->extend(
            'opensearch',
            fn (): OpenSearchEngine => new OpenSearchEngine(
                $this->app->make(Client::class),
                (bool) config('scout.soft_delete', false),
            ),
        );
    }
}
