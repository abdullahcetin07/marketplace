<?php

declare(strict_types=1);

namespace App\Modules\Localization\Application\Services;

use App\Modules\Localization\Domain\Contracts\CountryRepositoryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract;
use App\Modules\Localization\Domain\Contracts\TimezoneRepositoryContract;
use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Localization\Domain\Models\Language;
use App\Modules\Localization\Domain\Models\Timezone;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read API for the platform's locale data.
 *
 * Everything here is cached and read-heavy: a storefront request renders prices
 * and a language switcher, which would otherwise be four queries per page for
 * data that changes a few times a year.
 *
 * ADR-019: the caching lives in the repositories this service composes, not in
 * the Domain models and not here. The service type-hints the CONTRACTS, so it
 * can be unit-tested against fakes.
 *
 * Writes go through the models and their actions — this exists to make reads
 * cheap and consistent, not to own the aggregate.
 *
 * @see docs/localization.md
 */
final class LocalizationService
{
    public function __construct(
        private readonly LanguageRepositoryContract $languages,
        private readonly CurrencyRepositoryContract $currencies,
        private readonly CountryRepositoryContract $countries,
        private readonly TimezoneRepositoryContract $timezones,
    ) {}

    /**
     * @return Collection<int, Language>
     */
    public function languages(): Collection
    {
        return $this->languages->enabled();
    }

    /**
     * @return Collection<int, Currency>
     */
    public function currencies(): Collection
    {
        return $this->currencies->active();
    }

    /**
     * @return Collection<int, Country>
     */
    public function countries(): Collection
    {
        return $this->countries->active();
    }

    /**
     * @return Collection<int, Timezone>
     */
    public function timezones(): Collection
    {
        return $this->timezones->active();
    }

    public function defaultLanguage(): Language
    {
        return $this->languages->default();
    }

    public function defaultCurrency(): Currency
    {
        return $this->currencies->default();
    }

    public function defaultCountry(): ?Country
    {
        return $this->countries->default();
    }

    /**
     * The language in effect for this request.
     */
    public function currentLanguage(): Language
    {
        return $this->languages->current();
    }

    public function findLanguage(string $code): ?Language
    {
        return $this->languages->findByCode($code);
    }

    public function findCurrency(string $code): ?Currency
    {
        return $this->currencies->findByCode($code);
    }

    public function findCountry(string $iso2): ?Country
    {
        return $this->countries->findByIso2($iso2);
    }

    public function findTimezone(string $name): ?Timezone
    {
        return $this->timezones->findByName($name);
    }

    /**
     * Apply a language to the running request.
     *
     * Sets Laravel's locale AND the Carbon locale — without the second, dates
     * render in English inside an otherwise Turkish page, which is the kind of
     * bug that survives review because nobody checks a date format.
     */
    public function apply(Language $language): void
    {
        app()->setLocale($language->code);

        \Carbon\CarbonImmutable::setLocale($language->code);
        \Carbon\Carbon::setLocale($language->code);
    }

    /**
     * Negotiate a language from an Accept-Language header.
     *
     * Falls back to the platform default rather than 406-ing: a browser
     * preferring a locale we do not serve should still get a working page.
     */
    public function negotiate(?string $acceptLanguage): Language
    {
        if (blank($acceptLanguage)) {
            return $this->defaultLanguage();
        }

        $enabled = $this->languages()->keyBy(fn (Language $l): string => $l->code);

        foreach ($this->parseAcceptLanguage($acceptLanguage) as $candidate) {
            if ($enabled->has($candidate)) {
                return $enabled->get($candidate);
            }

            // 'tr-TR' should match the 'tr' language row.
            $base = explode('-', $candidate)[0];

            if ($enabled->has($base)) {
                return $enabled->get($base);
            }
        }

        return $this->defaultLanguage();
    }

    /**
     * Payload the Next.js storefront bootstraps from — one request instead of
     * four. Every component read is already cached by its repository.
     *
     * @return array<string, mixed>
     */
    public function bootstrapPayload(): array
    {
        return [
            'current_language' => $this->currentLanguage()->code,
            'default_currency' => $this->defaultCurrency()->code,
            'languages' => $this->languages()
                ->map(static fn (Language $l): array => [
                    'code' => $l->code,
                    'locale' => $l->locale,
                    'native_name' => $l->native_name,
                    'direction' => $l->direction->value,
                    'flag' => $l->flag,
                ])->all(),
            'currencies' => $this->currencies()
                ->map(static fn (Currency $c): array => [
                    'code' => $c->code,
                    'symbol' => $c->symbol,
                    'symbol_position' => $c->symbol_position->value,
                    'decimal_places' => $c->decimal_places,
                ])->all(),
        ];
    }

    /**
     * Parse and quality-sort an Accept-Language header.
     *
     * @return array<int, string>
     */
    private function parseAcceptLanguage(string $header): array
    {
        $entries = [];

        foreach (explode(',', $header) as $part) {
            $segments = explode(';q=', trim($part));
            $tag = mb_strtolower(trim($segments[0]));

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $entries[$tag] = isset($segments[1]) ? (float) $segments[1] : 1.0;
        }

        arsort($entries);

        return array_keys($entries);
    }
}
