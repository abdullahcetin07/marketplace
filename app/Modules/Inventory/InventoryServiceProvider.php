<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Modules\Inventory\Application\Listeners\MirrorOfferStock;
use App\Modules\Inventory\Domain\Contracts\StockItemRepositoryContract;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Infrastructure\Commands\InventoryReservation;
use App\Modules\Inventory\Infrastructure\Queries\InventoryQuery;
use App\Modules\Inventory\Infrastructure\Repositories\StockItemRepository;
use App\Modules\Inventory\Presentation\Policies\InventoryPolicy;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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

        $this->registerPermissions();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Inventory/migrations'));

        Gate::policy(StockItem::class, InventoryPolicy::class);

        $this->subscribeToOfferStock();
    }

    /**
     * Permissions are DERIVED from a resource registration, never hand-listed.
     *
     * READ VERBS ONLY, and only for admins. There is no `inventory.update`
     * anywhere, for anybody: a seller changes stock on the Offer form (ADR-048)
     * and an admin does not touch a merchant's stock at all — the same boundary
     * that stops an admin re-pricing a merchant's offer. A write permission that
     * existed would eventually be granted to somebody.
     *
     * A seller's authority over their own stock is an ORGANIZATION CAPABILITY
     * resolved through the Core contract (§7), not a Spatie permission: Spatie
     * `teams` is false, so a seller-guard `inventory.view` could not be scoped to
     * one company and would grant sight of everyone's.
     */
    private function registerPermissions(): void
    {
        PermissionRegistry::ability('inventory.view_any', [UserType::Admin]);
        PermissionRegistry::ability('inventory.view', [UserType::Admin]);
    }

    /**
     * The on-hand mirror (§3.1, ADR-048), subscribed BY CLASS-STRING.
     *
     * This is where the boundary is visible as a compromise rather than a rule.
     * Inventory imports no module and Offer is on the forbidden list with no
     * events escape hatch (`LayeringTest`), so it cannot name `OfferStockChanged`
     * as a class — it names it as a string and reads public properties off a
     * plain object.
     *
     * WHY NOT GIVE OFFER AN ESCAPE HATCH like Audit and Activity have? Because
     * those are consumers by nature: they subscribe to everything and never act
     * on a producer's data. Inventory is a peer — it holds the authority Offer
     * now READS BACK through `InventoryQueryContract` — and relaxing the rule for
     * one event class would make the next Offer import an argument rather than a
     * build failure.
     *
     * The cost is that a rename in Offer breaks the mirror at runtime rather than
     * at build time. It is bounded by a feature test that fires the real Offer
     * actions and asserts the stock pool moved.
     */
    private function subscribeToOfferStock(): void
    {
        // Both mean "the seller says they have N", so both land in one handler.
        foreach ([
            'App\Modules\Offer\Domain\Events\OfferCreated',
            'App\Modules\Offer\Domain\Events\OfferStockChanged',
        ] as $event) {
            Event::listen($event, [MirrorOfferStock::class, 'onStockDeclared']);
        }

        Event::listen(
            'App\Modules\Offer\Domain\Events\OfferWithdrawn',
            [MirrorOfferStock::class, 'onWithdrawn'],
        );
    }
}
