<?php

declare(strict_types=1);

namespace App\Modules\Localization\Infrastructure\Repositories;

use App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract;
use App\Modules\Localization\Domain\Models\Language;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cached language reads.
 *
 * ADR-019 moved this caching out of the model. It behaves identically — same
 * keys, same one-hour TTL, same flush-on-write — but the `cache()` call now
 * lives in Infrastructure where it belongs, and the Domain model is testable
 * without a cache store bound.
 *
 * Flushing is driven by LocalizationCacheObserver, registered in the module's
 * service provider.
 *
 * @see App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract
 * @see docs/localization.md
 */
final class LanguageRepository implements LanguageRepositoryContract
{
    private const int TTL = 3600;

    private const string KEY_DEFAULT = 'localization:language:default';

    private const string KEY_ENABLED = 'localization:languages:enabled';

    public function __construct(private readonly CacheRepository $cache) {}

    public function default(): Language
    {
        return $this->cache->remember(
            self::KEY_DEFAULT,
            self::TTL,
            static fn (): Language => Language::query()->where('is_default', true)->firstOrFail(),
        );
    }

    public function fallback(): ?Language
    {
        return $this->findByCode((string) config('marketplace.localization.fallback_language', 'en'));
    }

    public function findByCode(string $code): ?Language
    {
        $code = mb_strtolower($code);

        return $this->cache->remember(
            $this->codeKey($code),
            self::TTL,
            static fn (): ?Language => Language::query()->where('code', $code)->first(),
        );
    }

    /**
     * @return Collection<int, Language>
     */
    public function enabled(): Collection
    {
        return $this->cache->remember(
            self::KEY_ENABLED,
            self::TTL,
            static fn (): Collection => Language::query()->active()->ordered()->get(),
        );
    }

    public function current(): Language
    {
        return $this->findByCode(app()->getLocale()) ?? $this->default();
    }

    public function flush(?Language $language = null): void
    {
        $this->cache->forget(self::KEY_DEFAULT);
        $this->cache->forget(self::KEY_ENABLED);

        if ($language !== null) {
            $this->cache->forget($this->codeKey(mb_strtolower($language->code)));

            // A rename leaves the old code cached under its own key.
            $original = $language->getOriginal('code');

            if (is_string($original) && $original !== $language->code) {
                $this->cache->forget($this->codeKey(mb_strtolower($original)));
            }
        }
    }

    private function codeKey(string $code): string
    {
        return "localization:language:code:{$code}";
    }
}
