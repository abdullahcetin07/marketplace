<?php

declare(strict_types=1);

namespace Database\Modules\Localization\Seeders;

use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Localization\Domain\Models\Language;
use App\Modules\Localization\Domain\Models\Timezone;
use Illuminate\Database\Seeder;

/**
 * Seeds the locale data the platform cannot boot without.
 *
 * NOT demo data. Without a default language and a default currency,
 * the language/currency repository defaults throw and every request fails.
 * This seeder is part of installation, and is idempotent so it runs safely on
 * every deploy.
 *
 * The set is deliberately small — Türkiye plus the markets currently served.
 * Every country here implies tax, shipping and legal obligations, so widening
 * the list is a business decision, not a data-completeness exercise.
 */
final class LocalizationSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCurrencies();
        $this->seedTimezones();
        $this->seedCountries();
        $this->seedLanguages();
    }

    private function seedCurrencies(): void
    {
        /*
        | Turkish and most European formats: "1.499,90 ₺" — dot groups
        | thousands, comma marks decimals, symbol trails. US/UK invert both.
        | Storing this per currency rather than deriving it from the viewer's
        | locale means a Turkish admin sees USD formatted the way USD is
        | written.
        */
        $currencies = [
            ['code' => 'TRY', 'name' => 'Turkish Lira', 'native_name' => 'Türk Lirası', 'symbol' => '₺',
                'symbol_position' => 'after', 'decimal_separator' => ',', 'thousands_separator' => '.',
                'exchange_rate' => '1.0000000000', 'is_default' => true, 'sort_order' => 1],
            ['code' => 'USD', 'name' => 'US Dollar', 'native_name' => 'US Dollar', 'symbol' => '$',
                'symbol_position' => 'before', 'decimal_separator' => '.', 'thousands_separator' => ',',
                'exchange_rate' => '0.0290000000', 'is_default' => false, 'sort_order' => 2],
            ['code' => 'EUR', 'name' => 'Euro', 'native_name' => 'Euro', 'symbol' => '€',
                'symbol_position' => 'after', 'decimal_separator' => ',', 'thousands_separator' => '.',
                'exchange_rate' => '0.0270000000', 'is_default' => false, 'sort_order' => 3],
            ['code' => 'GBP', 'name' => 'Pound Sterling', 'native_name' => 'Pound Sterling', 'symbol' => '£',
                'symbol_position' => 'before', 'decimal_separator' => '.', 'thousands_separator' => ',',
                'exchange_rate' => '0.0230000000', 'is_default' => false, 'sort_order' => 4],
        ];

        foreach ($currencies as $attributes) {
            /*
            | updateOrCreate on `code`, but exchange_rate is NOT overwritten on
            | re-run: the seeded rates are placeholders, and a deploy must not
            | reset rates that the rate-update job has since refreshed.
            */
            $existing = Currency::query()->where('code', $attributes['code'])->first();

            if ($existing !== null) {
                unset($attributes['exchange_rate']);
                $existing->fill($attributes)->save();

                continue;
            }

            Currency::query()->create([...$attributes, 'decimal_places' => 2, 'is_active' => true]);
        }
    }

    private function seedTimezones(): void
    {
        // A curated list, not all ~600 IANA zones. An admin picker with 600
        // entries is unusable; these cover the markets served.
        $timezones = [
            ['name' => 'Europe/Istanbul', 'label' => 'İstanbul', 'offset_minutes' => 180, 'sort_order' => 1],
            ['name' => 'UTC', 'label' => 'UTC', 'offset_minutes' => 0, 'sort_order' => 2],
            ['name' => 'Europe/London', 'label' => 'London', 'offset_minutes' => 0, 'sort_order' => 3],
            ['name' => 'Europe/Berlin', 'label' => 'Berlin', 'offset_minutes' => 60, 'sort_order' => 4],
            ['name' => 'Europe/Amsterdam', 'label' => 'Amsterdam', 'offset_minutes' => 60, 'sort_order' => 5],
            ['name' => 'Europe/Paris', 'label' => 'Paris', 'offset_minutes' => 60, 'sort_order' => 6],
            ['name' => 'America/New_York', 'label' => 'New York', 'offset_minutes' => -300, 'sort_order' => 7],
        ];

        foreach ($timezones as $attributes) {
            Timezone::query()->updateOrCreate(
                ['name' => $attributes['name']],
                [...$attributes, 'is_active' => true],
            );
        }
    }

    private function seedCountries(): void
    {
        $currencies = Currency::query()->pluck('id', 'code');
        $timezones = Timezone::query()->pluck('id', 'name');

        $countries = [
            ['iso2' => 'TR', 'iso3' => 'TUR', 'numeric_code' => '792', 'name' => 'Türkiye', 'native_name' => 'Türkiye',
                'phone_code' => '90', 'currency' => 'TRY', 'timezone' => 'Europe/Istanbul', 'flag' => '🇹🇷',
                'capital' => 'Ankara', 'region' => 'Asia', 'is_eu_member' => false, 'sort_order' => 1],
            ['iso2' => 'DE', 'iso3' => 'DEU', 'numeric_code' => '276', 'name' => 'Germany', 'native_name' => 'Deutschland',
                'phone_code' => '49', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin', 'flag' => '🇩🇪',
                'capital' => 'Berlin', 'region' => 'Europe', 'is_eu_member' => true, 'sort_order' => 2],
            ['iso2' => 'GB', 'iso3' => 'GBR', 'numeric_code' => '826', 'name' => 'United Kingdom', 'native_name' => 'United Kingdom',
                'phone_code' => '44', 'currency' => 'GBP', 'timezone' => 'Europe/London', 'flag' => '🇬🇧',
                'capital' => 'London', 'region' => 'Europe', 'is_eu_member' => false, 'sort_order' => 3],
            ['iso2' => 'US', 'iso3' => 'USA', 'numeric_code' => '840', 'name' => 'United States', 'native_name' => 'United States',
                'phone_code' => '1', 'currency' => 'USD', 'timezone' => 'America/New_York', 'flag' => '🇺🇸',
                'capital' => 'Washington, D.C.', 'region' => 'Americas', 'is_eu_member' => false, 'sort_order' => 4],
            ['iso2' => 'NL', 'iso3' => 'NLD', 'numeric_code' => '528', 'name' => 'Netherlands', 'native_name' => 'Nederland',
                'phone_code' => '31', 'currency' => 'EUR', 'timezone' => 'Europe/Amsterdam', 'flag' => '🇳🇱',
                'capital' => 'Amsterdam', 'region' => 'Europe', 'is_eu_member' => true, 'sort_order' => 5],
            ['iso2' => 'FR', 'iso3' => 'FRA', 'numeric_code' => '250', 'name' => 'France', 'native_name' => 'France',
                'phone_code' => '33', 'currency' => 'EUR', 'timezone' => 'Europe/Paris', 'flag' => '🇫🇷',
                'capital' => 'Paris', 'region' => 'Europe', 'is_eu_member' => true, 'sort_order' => 6],
        ];

        foreach ($countries as $attributes) {
            $currencyCode = $attributes['currency'];
            $timezoneName = $attributes['timezone'];
            unset($attributes['currency'], $attributes['timezone']);

            Country::query()->updateOrCreate(
                ['iso2' => $attributes['iso2']],
                [
                    ...$attributes,
                    'currency_id' => $currencies[$currencyCode] ?? null,
                    'timezone_id' => $timezones[$timezoneName] ?? null,
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedLanguages(): void
    {
        $languages = [
            ['code' => 'tr', 'locale' => 'tr-TR', 'name' => 'Turkish', 'native_name' => 'Türkçe',
                'direction' => 'ltr', 'flag' => '🇹🇷', 'is_default' => true, 'sort_order' => 1],
            ['code' => 'en', 'locale' => 'en-GB', 'name' => 'English', 'native_name' => 'English',
                'direction' => 'ltr', 'flag' => '🇬🇧', 'is_default' => false, 'sort_order' => 2],
        ];

        foreach ($languages as $attributes) {
            Language::query()->updateOrCreate(
                ['code' => $attributes['code']],
                [...$attributes, 'is_active' => true],
            );
        }
    }
}
