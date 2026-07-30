<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

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
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Inventory/migrations'));
    }
}
