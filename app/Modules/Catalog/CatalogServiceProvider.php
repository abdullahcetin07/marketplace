<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

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
 * Phase 1 registers no `StorefrontContributorContract` (ADR-041): a store page
 * lists its Offers, and Offers do not exist yet.
 *
 * @see docs/modules/Catalog.md
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Catalog/migrations'));
    }
}
