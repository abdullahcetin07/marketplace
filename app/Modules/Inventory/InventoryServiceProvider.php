<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Modules\Inventory\Domain\Contracts\StockItemRepositoryContract;
use App\Modules\Inventory\Infrastructure\Commands\InventoryReservation;
use App\Modules\Inventory\Infrastructure\Queries\InventoryQuery;
use App\Modules\Inventory\Infrastructure\Repositories\StockItemRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Inventory module wiring.
 *
 * THE AVAILABILITY AUTHORITY (ADR-048). For each (seller organization, variant)
 * it holds **on-hand** and **reserved**, and answers the one question no other
 * module can: `available = on_hand − reserved` — how many can be sold this
 * instant. That number, not `Offer.stock_quantity`, is what the buy box reads.
 *
 * THE SELLER STILL TYPES STOCK ON THE OFFER FORM (owner decision). Inventory
 * mirrors it by subscribing to Offer's stock events BY CLASS-STRING — the same
 * name-is-not-an-import coupling Offer uses for Catalog — and records each as a
 * movement. So the same number lives in two places, kept in step by an event
 * rather than a shared row; the mirror is rebuildable from the Offer at any
 * time, which is what makes that trade acceptable.
 *
 * IT IMPORTS NO MODULE. Offer arrives as events resolved by name; org tenancy
 * comes from `OrganizationAuthorizationContract`; the catalog is read by uuid
 * through `CatalogQueryContract`. It exposes `InventoryQueryContract` (read) and
 * `InventoryReservationContract` (command) so Order — its first real caller —
 * will never import it either.
 *
 * WHAT THIS MODULE IS NOT: cart, checkout, orders, money, multiple warehouses,
 * supplier restocking. It counts units and lends them out under reservation.
 * Nothing decrements on-hand this sprint except the seller's own edits, because
 * a real decrement happens only when Order commits a reservation and Order does
 * not exist yet.
 *
 * @see docs/modules/Inventory.md
 */
final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StockItemRepositoryContract::class, StockItemRepository::class);

        /*
        | The downstream read port (ADR-048). Inventory is the single source of
        | truth for availability; the buy box and, later, Order ask through this
        | Core contract instead of importing Inventory.
        */
        $this->app->singleton(InventoryQueryContract::class, InventoryQuery::class);

        /*
        | The platform's first COMMAND port (ADR-049) — the only sanctioned way
        | another module mutates stock. Order will be its first real caller;
        | this sprint the tests are, which is the state that decision chose
        | deliberately.
        */
        $this->app->singleton(InventoryReservationContract::class, InventoryReservation::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Inventory/migrations'));
    }
}
