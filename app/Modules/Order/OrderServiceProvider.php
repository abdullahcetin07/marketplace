<?php

declare(strict_types=1);

namespace App\Modules\Order;

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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Order/migrations'));
    }
}
