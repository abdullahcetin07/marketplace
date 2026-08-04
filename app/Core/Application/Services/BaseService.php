<?php

declare(strict_types=1);

namespace App\Core\Application\Services;

use App\Core\Domain\Contracts\RepositoryContract;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * Orchestration layer for one aggregate.
 *
 * A service is the API a module presents to controllers, Filament resources
 * and other modules' event listeners. It composes repositories and actions;
 * it does not contain query building (that is the repository's job) and it
 * does not contain single-purpose business rules (that is an action's job).
 *
 * What this base class provides is the plumbing every service ends up needing
 * anyway — a scoped cache namespace, a tagged logger, and a transaction helper
 * — so that each concrete service is only domain code.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @see App\Core\Application\Actions\BaseAction for the action/service split
 */
abstract class BaseService
{
    /**
     * Default TTL in seconds for remember(). One hour is a deliberate middle
     * ground: long enough to absorb traffic spikes, short enough that a missed
     * invalidation self-heals within a support call.
     */
    protected int $cacheTtl = 3600;

    /**
     * Log channel. Services log to the daily channel by default; override to
     * 'audit' for services whose every call is a business-significant event.
     */
    protected string $logChannel = 'daily';

    /**
     * The repository this service is built around, when it has one.
     *
     * @var RepositoryContract<TModel>|null
     */
    protected ?RepositoryContract $repository = null;

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Cache namespace for this service, e.g. `svc:store_service`.
     *
     * Every cache key a service writes is prefixed with this, which makes
     * flush() safe: a service can only ever invalidate its own entries.
     */
    public function cachePrefix(): string
    {
        return 'svc:'.str(class_basename(static::class))->snake()->value();
    }

    /**
     * Drop every cache entry owned by this service.
     *
     * Requires a taggable store (Redis is, and is what we run everywhere).
     */
    public function flushCache(): bool
    {
        return $this->cache()->tags([$this->cachePrefix()])->flush();
    }

    /**
     * @return RepositoryContract<TModel>
     */
    protected function repository(): RepositoryContract
    {
        if ($this->repository === null) {
            throw new LogicException(static::class.' has no repository configured.');
        }

        return $this->repository;
    }

    /**
     * Read-through cache scoped to this service.
     *
     * @template TValue
     *
     * @param Closure(): TValue $callback
     *
     * @return TValue
     */
    protected function remember(string $key, Closure $callback, ?int $ttl = null): mixed
    {
        return $this->cache()
            ->tags([$this->cachePrefix()])
            ->remember(
                $this->cachePrefix().':'.$key,
                $ttl ?? $this->cacheTtl,
                $callback,
            );
    }

    protected function forget(string $key): bool
    {
        return $this->cache()
            ->tags([$this->cachePrefix()])
            ->forget($this->cachePrefix().':'.$key);
    }

    /**
     * Run a closure inside a transaction.
     *
     * Prefer pushing the transaction down into an action. Use this only when a
     * service genuinely must make several actions atomic as a group.
     *
     * @template TValue
     *
     * @param Closure(): TValue $callback
     *
     * @return TValue
     */
    protected function transaction(Closure $callback, int $attempts = 1): mixed
    {
        return DB::transaction($callback, $attempts);
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function log(string $message, array $context = [], string $level = 'info'): void
    {
        $this->logger()->log($level, $message, [
            'service' => static::class,
            ...$context,
        ]);
    }

    protected function logger(): LoggerInterface
    {
        return Log::channel($this->logChannel);
    }

    protected function cache(): CacheRepository
    {
        return Cache::store();
    }
}
