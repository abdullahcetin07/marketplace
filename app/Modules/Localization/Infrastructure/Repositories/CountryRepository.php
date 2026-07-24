<?php

declare(strict_types=1);

namespace App\Modules\Localization\Infrastructure\Repositories;

use App\Modules\Localization\Domain\Contracts\CountryRepositoryContract;
use App\Modules\Localization\Domain\Models\Country;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cached country reads. @see LanguageRepository for the ADR-019 reasoning.
 */
final class CountryRepository implements CountryRepositoryContract
{
    private const int TTL = 3600;

    private const string KEY_ACTIVE = 'localization:countries:active';

    public function __construct(private readonly CacheRepository $cache) {}

    public function default(): ?Country
    {
        return $this->findByIso2((string) config('marketplace.localization.default_country', 'TR'));
    }

    public function findByIso2(string $iso2): ?Country
    {
        $iso2 = mb_strtoupper($iso2);

        return $this->cache->remember(
            $this->isoKey($iso2),
            self::TTL,
            static fn (): ?Country => Country::query()->where('iso2', $iso2)->first(),
        );
    }

    /**
     * Eager-loads `currency`: the storefront's country picker renders a
     * currency per row, and strict mode makes a lazy load throw.
     *
     * @return Collection<int, Country>
     */
    public function active(): Collection
    {
        return $this->cache->remember(
            self::KEY_ACTIVE,
            self::TTL,
            static fn (): Collection => Country::query()->active()->ordered()->with('currency')->get(),
        );
    }

    public function flush(?Country $country = null): void
    {
        $this->cache->forget(self::KEY_ACTIVE);

        if ($country !== null) {
            $this->cache->forget($this->isoKey(mb_strtoupper($country->iso2)));
        }
    }

    private function isoKey(string $iso2): string
    {
        return "localization:country:iso2:{$iso2}";
    }
}
