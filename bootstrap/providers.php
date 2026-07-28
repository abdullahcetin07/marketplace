<?php

declare(strict_types=1);

/*
| Provider order matters in two places:
|
|  1. Localization must register before anything resolves the translator —
|     it swaps Laravel's FileLoader for the database-backed one.
|  2. Module providers register their permissions in register(), and
|     RolePermissionSeeder reads PermissionRegistry after all of them have run.
|
| Everything else is independent.
*/

return [
    // Framework-level
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\SearchServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,

    // Foundation modules (Sprint 1)
    App\Modules\Localization\LocalizationServiceProvider::class,
    App\Modules\Settings\SettingsServiceProvider::class,
    App\Modules\Identity\IdentityServiceProvider::class,
    App\Modules\Audit\AuditServiceProvider::class,
    // Activity after Identity: it subscribes to Identity's events.
    App\Modules\Activity\ActivityServiceProvider::class,
    App\Modules\Media\MediaServiceProvider::class,
    App\Modules\Notification\NotificationServiceProvider::class,

    // Business modules (Sprint: Organization)
    App\Modules\Organization\OrganizationServiceProvider::class,
    // Store after Organization: it subscribes to StoreOpeningApproved (ADR-032).
    App\Modules\Store\StoreServiceProvider::class,

    // Business modules (Sprint: Catalog)
    // Independent of Organization and Store — it references the proposing
    // company by uuid only and subscribes to nothing (ADR-040).
    App\Modules\Catalog\CatalogServiceProvider::class,

    // Panels last — they discover resources from the modules above.
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\SellerPanelProvider::class,
];
