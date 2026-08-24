<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Core\Domain\Contracts\CatalogQueryContract;
use App\Modules\Catalog\Application\Listeners\RefreshProductSellability;
use App\Modules\Catalog\Application\Listeners\SyncProductSearchIndex;
use App\Modules\Catalog\Domain\Contracts\AttributeRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\BrandRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\CategoryRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\CategorySlugGeneratorContract;
use App\Modules\Catalog\Domain\Contracts\ProductRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\SkuGeneratorContract;
use App\Modules\Catalog\Domain\Contracts\SlugRegistryContract;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Infrastructure\Generators\DefaultCatalogSlugGenerator;
use App\Modules\Catalog\Infrastructure\Generators\DefaultSkuGenerator;
use App\Modules\Catalog\Infrastructure\Queries\CatalogBrowse;
use App\Modules\Catalog\Infrastructure\Queries\CatalogQuery;
use App\Modules\Catalog\Infrastructure\Registries\SlugRegistry;
use App\Modules\Catalog\Infrastructure\Repositories\AttributeRepository;
use App\Modules\Catalog\Infrastructure\Repositories\BrandRepository;
use App\Modules\Catalog\Infrastructure\Repositories\CategoryRepository;
use App\Modules\Catalog\Infrastructure\Repositories\ProductRepository;
use App\Modules\Catalog\Presentation\Commands\BuildGoogleMerchantFeedCommand;
use App\Modules\Catalog\Presentation\Commands\FillProductDescriptionsCommand;
use App\Modules\Catalog\Presentation\Commands\RefreshSellabilityCommand;
use App\Modules\Catalog\Presentation\Policies\CategoryPolicy;
use App\Modules\Catalog\Presentation\Policies\ProductPolicy;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Catalog module wiring.
 *
 * The shared, platform-level product catalog (ADR-037): the taxonomy, brands,
 * products and variants. It is the first module built after the Organization
 * and Store freeze, and it imports NEITHER of them — the proposing company is
 * carried as a bare `proposed_by_org_id` + `proposed_by_org_uuid` pair, with no
 * relation and no model import (ADR-040/033).
 *
 * WHAT THIS MODULE IS NOT: price, stock, offers, orders or payments. A Product
 * here has no price and no stock, which is precisely what lets many sellers sell
 * one catalog entry (ADR-037). Those concerns arrive with Offer and Inventory.
 *
 * IT SUBSCRIBES TO NO OTHER MODULE. Unlike Store, the Catalog is not created by
 * another context's event, so there is no consumer-side import at all — the one
 * subscriber below listens to Catalog's own events.
 *
 * Phase 1 registers no `StorefrontContributorContract` (ADR-041): a store page
 * lists its Offers, and Offers do not exist yet.
 *
 * @see docs/modules/Catalog.md
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CategoryRepositoryContract::class, CategoryRepository::class);
        $this->app->singleton(BrandRepositoryContract::class, BrandRepository::class);
        $this->app->singleton(AttributeRepositoryContract::class, AttributeRepository::class);
        $this->app->singleton(ProductRepositoryContract::class, ProductRepository::class);

        // Slug and SKU policy behind contracts so the aggregates never encode
        // it and a future scheme swaps only the binding — the Store precedent.
        $this->app->singleton(SlugRegistryContract::class, SlugRegistry::class);
        $this->app->singleton(CategorySlugGeneratorContract::class, DefaultCatalogSlugGenerator::class);
        $this->app->singleton(SkuGeneratorContract::class, DefaultSkuGenerator::class);

        // The downstream read port (ADR-040). Catalog is the single source of
        // truth for what is in the catalog; Offer, Inventory and Search ask
        // through this Core contract instead of importing Catalog.
        $this->app->singleton(CatalogQueryContract::class, CatalogQuery::class);

        /*
        | The seller-facing browse port (ADR-046, Offer.md §8.2) — the one
        | change the Offer sprint makes to this module, and the reason Phase 1
        | was left unfrozen. A read contract only: no schema change, no new
        | model, nothing Catalog now depends on. Offer asks "what may I sell?"
        | through it and still imports nothing.
        */
        $this->app->singleton(CatalogBrowseContract::class, CatalogBrowse::class);

        $this->registerPermissions();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Catalog/migrations'));

        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);

        /*
        | Search (§10). Index on ProductPublished, drop on ProductArchived —
        | the only two transitions that change whether a product is findable.
        |
        | Catalog subscribing to Catalog's OWN events, which is not the
        | cross-module consumer pattern: it is simply where the reaction to a
        | published product belongs while Offer does not exist. When Offer
        | ships it subscribes to the same events from its own provider.
        */
        Event::subscribe(SyncProductSearchIndex::class);

        $this->listenForSellability();

        if ($this->app->runningInConsole()) {
            $this->commands([
                RefreshSellabilityCommand::class,
                BuildGoogleMerchantFeedCommand::class,
                FillProductDescriptionsCommand::class,
            ]);
        }

        /*
        | AUDIT is already wired — `Product` uses the Auditable trait (ADR-027),
        | so every field change on a catalog entry is recorded without a
        | listener. That is the whole mechanism for aggregates; the subscriber
        | pattern is for SECURITY events, which this module raises none of.
        |
        | ACTIVITY (the user timeline) is deliberately NOT wired here. A
        | timeline listener belongs to the Activity module, which would have to
        | import Catalog's events to write it — and `LayeringTest` states that
        | nothing imports Catalog. The same follow-up is open for Organization
        | (see its freeze notice), so this is the established shape rather than
        | an omission: one Activity change, later, covering both modules.
        */
    }

    /**
     * The module's permissions (§9).
     *
     * ABILITIES, NOT A `resource()` REGISTRATION. The generated CRUD verb set
     * would produce `category.restore`, `product.force_delete` and the rest —
     * permissions this module has no operation for, because a category is
     * deactivated rather than deleted (ADR-015) and a product is archived rather
     * than destroyed (§3.5). Naming only the ones that exist keeps the
     * permission table an accurate description of what the system can do.
     *
     * THE CATEGORY MANAGER FINALLY HAS A HOME. ADR-013 reserved the role for
     * exactly this module; until now it held only panel access. The grants
     * themselves are in RolePermissionSeeder — this only declares that the
     * permissions exist.
     *
     * `catalog.products.author` is registered on the SELLER guard alone.
     * Permissions are guard-scoped, so a seller's authoring right cannot be
     * confused with an admin's, and moderation is deliberately absent from the
     * seller guard entirely rather than merely unassigned.
     */
    private function registerPermissions(): void
    {
        PermissionRegistry::ability('catalog.taxonomy.manage', [UserType::Admin]);
        PermissionRegistry::ability('catalog.products.moderate', [UserType::Admin]);
        PermissionRegistry::ability('catalog.products.view_any', [UserType::Admin, UserType::Seller]);
        PermissionRegistry::ability('catalog.products.create', [UserType::Seller]);
        PermissionRegistry::ability('catalog.products.author', [UserType::Seller]);
    }

    /**
     * Keep `products.is_sellable` current (ADR-079).
     *
     * **BY CLASS-STRING, BECAUSE CATALOG IMPORTS NEITHER MODULE.** The same seam
     * Inventory uses to hear Offer's stock events. Naming the classes as strings
     * is what lets a listing filter on an indexed boolean instead of collecting
     * every sellable uuid and handing seven thousand of them to a `whereIn`.
     *
     * **BOTH HALVES OF THE FACT.** Offer's events say the listing changed;
     * Inventory's say the stock behind it did. A storefront that heard only the
     * first would keep offering the last unit of something already in somebody
     * else's basket.
     */
    private function listenForSellability(): void
    {
        foreach ([
            'App\Modules\Offer\Domain\Events\OfferCreated',
            'App\Modules\Offer\Domain\Events\OfferStockChanged',
            'App\Modules\Offer\Domain\Events\OfferWithdrawn',
            'App\Modules\Offer\Domain\Events\OfferPaused',
            'App\Modules\Offer\Domain\Events\OfferResumed',
            'App\Modules\Offer\Domain\Events\OfferSuspended',
            'App\Modules\Offer\Domain\Events\OfferReinstated',
        ] as $event) {
            Event::listen($event, [RefreshProductSellability::class, 'onOfferChanged']);
        }

        foreach ([
            'App\Modules\Inventory\Domain\Events\StockReserved',
            'App\Modules\Inventory\Domain\Events\StockReleased',
            'App\Modules\Inventory\Domain\Events\StockCommitted',
            'App\Modules\Inventory\Domain\Events\StockRestocked',
            'App\Modules\Inventory\Domain\Events\StockAdjusted',
        ] as $event) {
            Event::listen($event, [RefreshProductSellability::class, 'onStockMoved']);
        }
    }
}
