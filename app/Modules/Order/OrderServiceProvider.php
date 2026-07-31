<?php

declare(strict_types=1);

namespace App\Modules\Order;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Order\Domain\Contracts\CartRepositoryContract;
use App\Modules\Order\Domain\Contracts\CustomerAddressRepositoryContract;
use App\Modules\Order\Domain\Contracts\OrderNumberGeneratorContract;
use App\Modules\Order\Domain\Contracts\OrderRepositoryContract;
use App\Modules\Order\Infrastructure\Generators\DefaultOrderNumberGenerator;
use App\Modules\Order\Infrastructure\Queries\OrderQuery;
use App\Modules\Order\Infrastructure\Repositories\CartRepository;
use App\Modules\Order\Infrastructure\Repositories\CustomerAddressRepository;
use App\Modules\Order\Infrastructure\Repositories\OrderRepository;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Presentation\Policies\OrderPolicy;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Order module wiring.
 *
 * THE BUYER'S PIPELINE, and the platform's largest module (ADR-052…056). A
 * logged-in Customer fills ONE multi-seller cart, checks out, and the checkout
 * SPLITS into one `Order` per seller under a shared `checkout_group_uuid` — one
 * purchase to the customer, N independent orders to the sellers who each fulfil,
 * ship and get paid separately (ADR-052).
 *
 * IT IS INVENTORY'S FIRST REAL CALLER (ADR-054). Checkout reserves, placement
 * commits, cancel and the expiry sweep release — all through the Core
 * `InventoryReservationContract`, keyed on the order uuid. That contract shipped
 * a sprint early with only tests driving it; this is the module it was built for,
 * and the first time the platform drives a Core COMMAND port rather than a read.
 *
 * ORDER LINES ARE IMMUTABLE SNAPSHOTS (ADR-053). Price, product title, variant
 * label, KDV rate and both addresses are frozen at placement. The catalog and the
 * offer may all change afterwards; an order records what was bought, at what
 * price, at what tax — forever, because it is a financial and legal record.
 *
 * IT STOPS AT AWAITING PAYMENT. Nothing here charges a card, computes commission,
 * or ships anything (ADR-055): those are Payment/Finance and Shipping, later and
 * separately reviewed. The customer side is API-only until the Next.js storefront
 * exists.
 *
 * IT IMPORTS NO MODULE. Offer, Inventory, Catalog, Store and Organization are all
 * read through Core contracts by uuid, and it publishes `OrderQueryContract` so
 * Payment and Shipping will not import it either. `LayeringTest` fails the build
 * on any import, in both directions.
 *
 * @see docs/modules/Order.md
 */
final class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('order.php'), 'order');

        $this->app->singleton(CartRepositoryContract::class, CartRepository::class);
        $this->app->singleton(CustomerAddressRepositoryContract::class, CustomerAddressRepository::class);
        $this->app->singleton(OrderRepositoryContract::class, OrderRepository::class);

        /*
        | The order number scheme, behind a contract so the aggregate does not
        | encode it — the Catalog SKU and Store slug precedent. A business that
        | later wants per-year sequences or a branch prefix swaps this binding
        | rather than editing the action that places orders.
        */
        $this->app->singleton(OrderNumberGeneratorContract::class, DefaultOrderNumberGenerator::class);

        /*
        | The downstream read port (§5). Published with the module and with no
        | caller yet, exactly as Inventory's contracts were: when Payment arrives,
        | "how do I read an order" is already answered and the answer is not
        | "import the model".
        */
        $this->app->singleton(OrderQueryContract::class, OrderQuery::class);

        $this->registerPermissions();
    }

    /**
     * Permissions are DERIVED from a registration, never hand-listed.
     *
     * ADMIN ONLY, and only three verbs. A customer's authority over their own
     * order is OWNERSHIP and a seller's is MEMBERSHIP of the selling company —
     * neither is a Spatie permission, because Spatie `teams` is false and a
     * seller-guard `order.view` could not be scoped to one company. It would grant
     * sight of every merchant's orders on the platform.
     *
     * THERE IS NO `order.update` FOR ANYBODY. The lines are immutable (ADR-053)
     * and the totals are written once; a wrong order is cancelled and re-placed.
     * `order.cancel` is the reactive lever — the Offer-suspension shape (ADR-044).
     */
    private function registerPermissions(): void
    {
        PermissionRegistry::ability('order.view_any', [UserType::Admin]);
        PermissionRegistry::ability('order.view', [UserType::Admin]);
        PermissionRegistry::ability('order.cancel', [UserType::Admin]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Order/migrations'));

        Gate::policy(Order::class, OrderPolicy::class);
    }
}
