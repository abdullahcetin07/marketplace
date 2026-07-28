<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Core\Domain\Contracts\CatalogQueryContract;
use App\Modules\Catalog\Domain\Contracts\AttributeRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\BrandRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\CategoryRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\CategorySlugGeneratorContract;
use App\Modules\Catalog\Domain\Contracts\ProductRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\SkuGeneratorContract;
use App\Modules\Catalog\Infrastructure\Generators\DefaultCatalogSlugGenerator;
use App\Modules\Catalog\Infrastructure\Generators\DefaultSkuGenerator;
use App\Modules\Catalog\Infrastructure\Queries\CatalogQuery;
use App\Modules\Catalog\Infrastructure\Repositories\AttributeRepository;
use App\Modules\Catalog\Infrastructure\Repositories\BrandRepository;
use App\Modules\Catalog\Infrastructure\Repositories\CategoryRepository;
use App\Modules\Catalog\Infrastructure\Repositories\ProductRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Catalog module wiring.
 *
 * The shared, platform-level product catalog (ADR-037): the taxonomy, brands,
 * products and variants. It is the first module built after the Organization
 * and Store freeze, and it imports NEITHER of them — the proposing company is
 * carried as `proposed_by_org_uuid` and nothing else (ADR-040).
 *
 * WHAT THIS MODULE IS NOT: price, stock, offers, orders or payments. A Product
 * here has no price and no stock, which is precisely what lets many sellers sell
 * one catalog entry (ADR-037). Those concerns arrive with Offer and Inventory.
 *
 * IT SUBSCRIBES TO NOTHING. Unlike Store, the Catalog is not created by another
 * context's event, so there is no `Event::subscribe()` here and no consumer-side
 * import at all.
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
        $this->app->singleton(CategorySlugGeneratorContract::class, DefaultCatalogSlugGenerator::class);
        $this->app->singleton(SkuGeneratorContract::class, DefaultSkuGenerator::class);

        // The downstream read port (ADR-040). Catalog is the single source of
        // truth for what is in the catalog; Offer, Inventory and Search ask
        // through this Core contract instead of importing Catalog.
        $this->app->singleton(CatalogQueryContract::class, CatalogQuery::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Catalog/migrations'));
    }
}
