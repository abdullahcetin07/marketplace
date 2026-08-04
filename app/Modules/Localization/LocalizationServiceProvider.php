<?php

declare(strict_types=1);

namespace App\Modules\Localization;

use App\Modules\Localization\Domain\Contracts\CountryRepositoryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Localization\Domain\Contracts\GeoRepositoryContract;
use App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract;
use App\Modules\Localization\Domain\Contracts\TimezoneRepositoryContract;
use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Localization\Domain\Models\Language;
use App\Modules\Localization\Domain\Models\Timezone;
use App\Modules\Localization\Domain\Models\Translation;
use App\Modules\Localization\Infrastructure\DatabaseTranslationLoader;
use App\Modules\Localization\Infrastructure\Observers\LocalizationCacheObserver;
use App\Modules\Localization\Infrastructure\Repositories\CountryRepository;
use App\Modules\Localization\Infrastructure\Repositories\CurrencyRepository;
use App\Modules\Localization\Infrastructure\Repositories\GeoRepository;
use App\Modules\Localization\Infrastructure\Repositories\LanguageRepository;
use App\Modules\Localization\Infrastructure\Repositories\TimezoneRepository;
use App\Modules\Localization\Presentation\Policies\CountryPolicy;
use App\Modules\Localization\Presentation\Policies\CurrencyPolicy;
use App\Modules\Localization\Presentation\Policies\LanguagePolicy;
use App\Modules\Localization\Presentation\Policies\TimezonePolicy;
use App\Modules\Localization\Presentation\Policies\TranslationPolicy;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\TranslationServiceProvider;

/**
 * Localization module wiring.
 *
 * @see docs/localization.md
 */
final class LocalizationServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private const array POLICIES = [
        Language::class => LanguagePolicy::class,
        Country::class => CountryPolicy::class,
        Currency::class => CurrencyPolicy::class,
        Timezone::class => TimezonePolicy::class,
        Translation::class => TranslationPolicy::class,
    ];

    public function register(): void
    {
        /*
        | Repository bindings. ADR-019 moved cached locale reads out of the
        | Domain models and behind these ports; services and Presentation
        | type-hint the CONTRACT, never the concrete repository, so both stay
        | testable against a fake.
        */
        $this->app->singleton(LanguageRepositoryContract::class, LanguageRepository::class);
        $this->app->singleton(CurrencyRepositoryContract::class, CurrencyRepository::class);
        $this->app->singleton(CountryRepositoryContract::class, CountryRepository::class);
        $this->app->singleton(GeoRepositoryContract::class, GeoRepository::class);
        $this->app->singleton(TimezoneRepositoryContract::class, TimezoneRepository::class);

        /*
        | Swap Laravel's FileLoader for one that overlays database overrides.
        |
        | Registered here rather than in a bootstrap file because it must be
        | bound BEFORE the translator singleton is resolved, and Laravel
        | resolves the translator lazily on first __() call.
        */
        $this->app->singleton('translation.loader', function ($app): DatabaseTranslationLoader {
            return new DatabaseTranslationLoader($app['files'], $app['path.lang']);
        });

        // Re-register the translator so it picks up the loader above.
        $this->app->register(TranslationServiceProvider::class);

        $this->registerPermissions();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Localization/migrations'));

        /*
        | Cache invalidation on write. Lives in Infrastructure (ADR-019) —
        | it used to sit in each model's booted() hook, calling cache() from
        | the Domain layer.
        */
        foreach ([Language::class, Currency::class, Country::class, Timezone::class, Translation::class] as $model) {
            $model::observe(LocalizationCacheObserver::class);
        }

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    /**
     * Permissions are DERIVED, never hand-listed. Registering the resource
     * expands it into the full verb set for each guard.
     *
     * Localization is admin-only: sellers and customers consume locale data
     * but never manage it.
     *
     * @see App\Shared\Support\PermissionRegistry
     */
    private function registerPermissions(): void
    {
        PermissionRegistry::resource('language', [UserType::Admin]);
        PermissionRegistry::resource('country', [UserType::Admin]);
        PermissionRegistry::resource('currency', [UserType::Admin]);
        PermissionRegistry::resource('timezone', [UserType::Admin]);
        PermissionRegistry::resource('translation', [UserType::Admin]);

        // Non-CRUD abilities that do not fit the verb pattern.
        PermissionRegistry::ability('currency.update_rates', [UserType::Admin]);
        PermissionRegistry::ability('translation.import', [UserType::Admin]);
        PermissionRegistry::ability('translation.export', [UserType::Admin]);
    }
}
