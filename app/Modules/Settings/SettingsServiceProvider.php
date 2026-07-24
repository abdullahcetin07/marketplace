<?php

declare(strict_types=1);

namespace App\Modules\Settings;

use App\Modules\Settings\Application\Services\SettingsService;
use App\Modules\Settings\Domain\Models\Setting;
use App\Modules\Settings\Infrastructure\Observers\SettingCacheObserver;
use App\Modules\Settings\Presentation\Policies\SettingPolicy;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Settings module wiring.
 *
 * @see docs/settings.md
 */
final class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
        | Singleton so the per-request memo inside the service is shared. A
        | bound-fresh instance per resolution would re-read the cache on every
        | injection point.
        */
        $this->app->singleton(SettingsService::class);

        PermissionRegistry::resource('setting', [UserType::Admin]);

        // Restricted groups get their own permission so an Editor holding
        // `setting.update` still cannot change security or system settings.
        PermissionRegistry::ability('setting.manage_restricted', [UserType::Admin]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Settings/migrations'));

        // Cache invalidation lives in Infrastructure (ADR-019).
        Setting::observe(SettingCacheObserver::class);

        Gate::policy(Setting::class, SettingPolicy::class);
    }
}
