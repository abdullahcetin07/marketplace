<?php

declare(strict_types=1);

namespace App\Modules\Localization\Infrastructure\Observers;

use App\Modules\Localization\Domain\Contracts\CountryRepositoryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract;
use App\Modules\Localization\Domain\Contracts\TimezoneRepositoryContract;
use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Localization\Domain\Models\Language;
use App\Modules\Localization\Domain\Models\Timezone;
use App\Modules\Localization\Domain\Models\Translation;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * Flushes cached locale reads when a row changes.
 *
 * ADR-019 moved this out of the models' `booted()` hooks, which called
 * `cache()` directly from the Domain layer. The behaviour is unchanged; only
 * its location is.
 *
 * ONE OBSERVER FOR FIVE MODELS rather than five near-identical classes: the
 * logic is a single dispatch on model type, and five files would be five places
 * for the cache-key knowledge to drift out of step with the repositories.
 *
 * Registered in LocalizationServiceProvider.
 *
 * @see docs/localization.md
 */
final class LocalizationCacheObserver
{
    public function __construct(
        private readonly LanguageRepositoryContract $languages,
        private readonly CurrencyRepositoryContract $currencies,
        private readonly CountryRepositoryContract $countries,
        private readonly TimezoneRepositoryContract $timezones,
        private readonly CacheRepository $cache,
    ) {}

    public function saved(Model $model): void
    {
        $this->flush($model);
    }

    public function deleted(Model $model): void
    {
        $this->flush($model);
    }

    private function flush(Model $model): void
    {
        match (true) {
            $model instanceof Language => $this->languages->flush($model),
            $model instanceof Currency => $this->currencies->flush($model),
            $model instanceof Country => $this->countries->flush($model),
            $model instanceof Timezone => $this->timezones->flush($model),
            // Translations are cached by the translation loader, keyed by
            // (language, group) — not by a repository.
            $model instanceof Translation => $this->flushTranslation($model),
            default => null,
        };

        // A country's currency or timezone relation changing invalidates the
        // eager-loaded `active()` country list too.
        if ($model instanceof Currency || $model instanceof Timezone) {
            $this->countries->flush();
        }
    }

    private function flushTranslation(Translation $translation): void
    {
        $this->cache->forget(sprintf(
            'localization:translations:%d:%s',
            $translation->language_id,
            $translation->group,
        ));
    }
}
