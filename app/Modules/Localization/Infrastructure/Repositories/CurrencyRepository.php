<?php

declare(strict_types=1);

namespace App\Modules\Localization\Infrastructure\Repositories;

use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Localization\Domain\Models\Currency;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cached currency reads. @see LanguageRepository for the ADR-019 reasoning.
 */
final class CurrencyRepository implements CurrencyRepositoryContract
{
    private const int TTL = 3600;

    private const string KEY_DEFAULT = 'localization:currency:default';

    private const string KEY_ACTIVE = 'localization:currencies:active';

    public function __construct(private readonly CacheRepository $cache) {}

    public function default(): Currency
    {
        return $this->cache->remember(
            self::KEY_DEFAULT,
            self::TTL,
            static fn (): Currency => Currency::query()->where('is_default', true)->firstOrFail(),
        );
    }

    public function findByCode(string $code): ?Currency
    {
        $code = mb_strtoupper($code);

        return $this->cache->remember(
            $this->codeKey($code),
            self::TTL,
            static fn (): ?Currency => Currency::query()->where('code', $code)->first(),
        );
    }

    /**
     * @return Collection<int, Currency>
     */
    public function active(): Collection
    {
        return $this->cache->remember(
            self::KEY_ACTIVE,
            self::TTL,
            static fn (): Collection => Currency::query()->active()->ordered()->get(),
        );
    }

    public function flush(?Currency $currency = null): void
    {
        $this->cache->forget(self::KEY_DEFAULT);
        $this->cache->forget(self::KEY_ACTIVE);

        if ($currency !== null) {
            $this->cache->forget($this->codeKey(mb_strtoupper($currency->code)));
        }
    }

    private function codeKey(string $code): string
    {
        return "localization:currency:code:{$code}";
    }
}
