<?php

declare(strict_types=1);

namespace App\Modules\Store;

use App\Core\Domain\Contracts\StoreQueryContract;
use App\Modules\Store\Application\Listeners\CreateStoreFromApprovedRequest;
use App\Modules\Store\Domain\Contracts\StoreNumberGeneratorContract;
use App\Modules\Store\Domain\Contracts\StoreRepositoryContract;
use App\Modules\Store\Domain\Contracts\StoreSlugGeneratorContract;
use App\Modules\Store\Infrastructure\Generators\DefaultStoreSlugGenerator;
use App\Modules\Store\Infrastructure\Generators\RandomStoreNumberGenerator;
use App\Modules\Store\Domain\Models\Store;
use App\Modules\Store\Infrastructure\Queries\StoreQuery;
use App\Modules\Store\Infrastructure\Repositories\StoreRepository;
use App\Modules\Store\Presentation\Policies\StorePolicy;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Store module wiring.
 *
 * Store is the storefront — an independent bounded context (ADR-033). It is
 * created only by consuming Organization's `StoreOpeningApproved` (ADR-028/032)
 * and reports back with `StoreCreated`. It imports only Core, Shared,
 * Localization and Organization's Domain\Events; never Organization's internals.
 *
 * @see docs/modules/Store.md
 */
final class StoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StoreRepositoryContract::class, StoreRepository::class);

        // Slug + number policy behind contracts so the aggregate never encodes
        // it and a future scheme swaps only the binding (Store.md §Slug/Number).
        $this->app->singleton(StoreSlugGeneratorContract::class, DefaultStoreSlugGenerator::class);
        $this->app->singleton(StoreNumberGeneratorContract::class, RandomStoreNumberGenerator::class);

        // The downstream read port (ADR-033). Store is the single source of
        // truth for storefront state; future modules ask through this Core
        // contract instead of importing Store.
        $this->app->singleton(StoreQueryContract::class, StoreQuery::class);

        $this->registerPermissions();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Store/migrations'));

        Gate::policy(Store::class, StorePolicy::class);

        // Store subscribes to Organization's approval event and creates the
        // storefront. Organization never calls Store — the wiring is here, in
        // the consumer, exactly as Activity/Audit subscribe to Identity.
        Event::subscribe(CreateStoreFromApprovedRequest::class);
    }

    /**
     * The ADMIN surface only. Seller store management is authorised through the
     * Core OrganizationAuthorizationContract (org capabilities), never Spatie
     * permissions — so no seller permissions are registered here. Admins hold
     * the cross-org read + enforcement powers.
     */
    private function registerPermissions(): void
    {
        PermissionRegistry::ability('store.view_any', [UserType::Admin]);
        PermissionRegistry::ability('store.view', [UserType::Admin]);
        PermissionRegistry::ability('store.suspend', [UserType::Admin]);
        PermissionRegistry::ability('store.reinstate', [UserType::Admin]);
        PermissionRegistry::ability('store.archive', [UserType::Admin]);
    }
}
