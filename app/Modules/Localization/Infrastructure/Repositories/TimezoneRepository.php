<?php

declare(strict_types=1);

namespace App\Modules\Localization\Infrastructure\Repositories;

use App\Modules\Localization\Domain\Contracts\TimezoneRepositoryContract;
use App\Modules\Localization\Domain\Models\Timezone;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cached timezone reads. @see LanguageRepository for the ADR-019 reasoning.
 */
final class TimezoneRepository implements TimezoneRepositoryContract
{
    private const int TTL = 3600;

    private const string KEY_ACTIVE = 'localization:timezones:active';

    public function __construct(private readonly CacheRepository $cache) {}

    public function default(): ?Timezone
    {
        return $this->findByName((string) config('app.timezone', 'Europe/Istanbul'));
    }

    public function findByName(string $name): ?Timezone
    {
        return $this->cache->remember(
            $this->nameKey($name),
            self::TTL,
            static fn (): ?Timezone => Timezone::query()->where('name', $name)->first(),
        );
    }

    /**
     * @return Collection<int, Timezone>
     */
    public function active(): Collection
    {
        return $this->cache->remember(
            self::KEY_ACTIVE,
            self::TTL,
            static fn (): Collection => Timezone::query()->active()->ordered()->get(),
        );
    }

    public function flush(?Timezone $timezone = null): void
    {
        $this->cache->forget(self::KEY_ACTIVE);

        if ($timezone !== null) {
            $this->cache->forget($this->nameKey($timezone->name));
        }
    }

    /**
     * IANA names contain slashes, which are legal in a cache key but awkward
     * in some stores. Hashed for safety.
     */
    private function nameKey(string $name): string
    {
        return 'localization:timezone:'.md5($name);
    }
}
