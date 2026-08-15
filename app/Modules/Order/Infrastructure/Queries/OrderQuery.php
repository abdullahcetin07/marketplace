<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Queries;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Core\Domain\Contracts\ShipmentQueryContract;
use App\Modules\Order\Application\Services\CartPricingService;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Cart;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Order's implementation of the downstream read port (§5).
 *
 * RETURNS SCALARS AND PLAIN ARRAYS, never models — the discipline every Core
 * query contract keeps, so Payment and Shipping cannot reach through the port
 * into an order's internals and start driving its lifecycle.
 *
 * NO CALLER EXISTS YET, and that is deliberate rather than premature: publishing
 * the port with the module means "how does another context read an order" is
 * already answered when Payment arrives, instead of being decided under deadline
 * by whoever needs it first. Inventory's contracts shipped the same way a sprint
 * early, and Order — their first real caller — found them ready.
 *
 * @see App\Core\Domain\Contracts\OrderQueryContract
 * @see docs/modules/Order.md §5
 */
final class OrderQuery implements OrderQueryContract
{
    /**
     * The statuses that mean somebody actually bought the thing (ADR-077/078).
     *
     * **NOT `Refunded`.** The money went back, so counting it would let a product
     * that everybody returned lead the homepage. Not `AwaitingPayment` either: an
     * abandoned card form is not a sale, and an `Expired` one already gave its
     * stock back (ADR-072).
     */
    private const array SOLD_STATUSES = [
        OrderStatus::Paid->value,
        OrderStatus::Delivered->value,
    ];

    /**
     * **DELIVERY IS SHIPPING'S FACT, ASKED THROUGH CORE** (ADR-064/083). Order
     * knows a seller-order reached `Delivered`; the DATE lives on the shipment,
     * and the points sweep needs it to measure the return window from.
     */
    public function __construct(private readonly ShipmentQueryContract $shipments) {}

    public function orderExists(string $orderUuid): bool
    {
        return Order::query()->where('uuid', $orderUuid)->exists();
    }

    public function orderStatus(string $orderUuid): ?string
    {
        $status = Order::query()->where('uuid', $orderUuid)->value('status');

        /*
        | A STRING, not the enum: typing this with `OrderStatus` would make every
        | consumer import the module the port exists to avoid importing.
        |
        | `value()` comes back as the CAST value, so this is already an enum
        | instance and unwrapping it is the whole job — reading it as a string
        | would silently answer null for every order that exists.
        */
        return $status instanceof OrderStatus ? $status->value : null;
    }

    /**
     * @return array<int, string>
     */
    public function ordersForCheckoutGroup(string $checkoutGroupUuid): array
    {
        return Order::query()
            ->inCheckoutGroup($checkoutGroupUuid)
            ->orderBy('id')
            ->pluck('uuid')
            ->all();
    }

    /**
     * Added for S4 (2026-08-06) — the inverse of `ordersForCheckoutGroup()`, so a
     * buyer's return can find the payment its order was charged on without
     * scanning every settled payment. @see the contract.
     */
    public function checkoutGroupFor(string $orderUuid): ?string
    {
        $group = Order::query()->where('uuid', $orderUuid)->value('checkout_group_uuid');

        return is_string($group) ? $group : null;
    }

    /**
     * Added for Payment (2026-08-04) — see the contract for why a Payment row
     * keyed to a GROUP still has to know whose money it is.
     *
     * THE EMAIL COMES FROM THE ORDER'S OWN CUSTOMER RECORD, read through the
     * users table by id rather than joined: Order holds the ADR-040 pair and no
     * relation to `User`, and the PSP needs an address to send a receipt to.
     *
     * @return array{id: int, uuid: string, email: string}|null
     */
    public function checkoutGroupCustomer(string $checkoutGroupUuid): ?array
    {
        $order = Order::query()
            ->inCheckoutGroup($checkoutGroupUuid)
            ->orderBy('id')
            ->first(['customer_id', 'customer_uuid']);

        if ($order === null) {
            return null;
        }

        $email = DB::table('users')->where('id', $order->customer_id)->value('email');

        return [
            'id' => (int) $order->customer_id,
            'uuid' => (string) $order->customer_uuid,
            // Empty rather than null when the account has gone: a payment is not
            // worth refusing over a missing receipt address, and the PSP will take
            // the charge either way.
            'email' => is_string($email) ? $email : '',
        ];
    }

    /**
     * Added for Payment (2026-08-04) — the PSP basket, and from P2 the commission
     * resolver's input.
     *
     * `id` AND `commission_minor` JOINED IN S4 (2026-08-06) — see the contract.
     *
     * @return array<int, array{id: string, variant_uuid: string, product_uuid: string, title: string, quantity: int, unit_price_minor: int, line_total_minor: int, tax_rate: string, commission_minor: int|null}>
     */
    public function orderLines(string $orderUuid): array
    {
        $order = Order::query()->with('lines')->where('uuid', $orderUuid)->first();

        if ($order === null) {
            return [];
        }

        return $order->lines
            ->map(static fn (OrderLine $line): array => [
                // The line's PUBLIC id — what a return names. Added by S4: a
                // partial refund is "this line, this many", and the caller needs
                // a handle for the line that is not its position in an array.
                'id' => $line->uuid,
                'variant_uuid' => $line->variant_uuid,
                'product_uuid' => $line->product_uuid,
                // The title AS BOUGHT (ADR-053), which is what belongs on a
                // payment page — a shopper must recognise what they are paying
                // for, even if the catalogue has since renamed it.
                'title' => $line->product_title,
                'quantity' => $line->quantity,
                'unit_price_minor' => $line->unit_price_minor,
                'line_total_minor' => $line->line_total_minor,
                'tax_rate' => $line->tax_rate,
                /*
                | THE FROZEN COMMISSION, null until payment settles it (ADR-061).
                | A partial refund reverses it PROPORTIONALLY — the frozen figure
                | scaled by the refunded share — so the caller must read what was
                | actually taken rather than resolving the rules a second time.
                */
                'commission_minor' => $line->commission_minor,
            ])
            ->values()
            ->all();
    }

    /**
     * Added for Payment (2026-08-04) — see the contract for why the reference
     * FORMAT stays in this module.
     *
     * BUILT BY THE AGGREGATE, not by string concatenation here: `Order::
     * reservationReferenceFor()` is the one definition of the key, and the whole
     * reason this method exists is so nothing outside Order ever writes that
     * colon.
     *
     * @return array<string, string>
     */
    public function reservationReferencesFor(string $orderUuid): array
    {
        $order = Order::query()->with('lines')->where('uuid', $orderUuid)->first();

        if ($order === null) {
            return [];
        }

        /*
        | KEYED BY VARIANT SINCE S4 (2026-08-06). P5 refunded whole orders and
        | wanted every reference; a line-level refund needs ONE of them, and
        | without the key its caller has to build `{order}:{variant}` itself —
        | which is precisely the drift this method exists to prevent. A foreach
        | over the values is unaffected.
        */
        return $order->lines
            ->mapWithKeys(static fn (OrderLine $line): array => [
                $line->variant_uuid => $order->reservationReferenceFor($line->variant_uuid),
            ])
            ->all();
    }

    /**
     * Added for Reviews (2026-08-06, ADR-067) — the review gate. See the contract
     * for why it returns lines rather than a boolean, and for the two recorded
     * deviations from the module spec's sketch.
     *
     * THE LINES ARE CONSTRAINED IN THE EAGER LOAD, not filtered afterwards: an
     * order of thirty items where one is this product should fetch one line, not
     * thirty. Orders with no matching line survive the load and contribute
     * nothing, which `flatMap` drops for free.
     *
     * @return array<int, array{order_line_uuid: string, store_uuid: string, selling_org_uuid: string, variant_uuid: string|null, variant_label: string|null, product_title: string, purchased_at: string|null}>
     */
    public function deliveredPurchaseLines(string $customerUuid, string $productUuid): array
    {
        return Order::query()
            ->where('customer_uuid', $customerUuid)
            // DELIVERED, NOT PAID (ADR-067). A parcel that has not arrived has
            // nothing to report.
            ->where('status', OrderStatus::Delivered->value)
            ->with(['lines' => fn ($query) => $query->where('product_uuid', $productUuid)])
            ->get()
            ->flatMap(static fn (Order $order): array => $order->lines
                ->map(static fn (OrderLine $line): array => [
                    'order_line_uuid' => $line->uuid,
                    // THE SELLER TAG (ADR-066) — copied from the order, so a
                    // review can never be attributed to a shop the buyer did not
                    // buy from.
                    'store_uuid' => $order->store_uuid,
                    'selling_org_uuid' => $order->selling_org_uuid,
                    'variant_uuid' => $line->variant_uuid,
                    'variant_label' => $line->variant_label,
                    // The title AS BOUGHT (ADR-053), so the screen offering the
                    // review names what the customer actually received.
                    'product_title' => $line->product_title,
                    // @see the contract: this is the ORDER date, and it is named
                    // after what it is because no delivery date exists here.
                    'purchased_at' => $order->placed_at?->toIso8601String(),
                ])
                ->all())
            ->values()
            ->all();
    }

    /**
     * Added for Payment's seller ledger (2026-08-04) — see the contract for why
     * this reads the frozen snapshot rather than recomputing.
     *
     * NULL COMMISSION MEANS "NOT SETTLED YET", not "no commission". It is null on
     * every line of an unpaid order and on every line placed before the ADR-061
     * migration, and the two are indistinguishable from here — which is why the
     * caller is told to treat null as unsettled rather than as zero.
     *
     * @return array{selling_org_uuid: string, commission_minor: int|null}|null
     */
    public function orderSettlement(string $orderUuid): ?array
    {
        $order = Order::query()->with('lines')->where('uuid', $orderUuid)->first();

        if ($order === null) {
            return null;
        }

        $lines = $order->lines;

        return [
            'selling_org_uuid' => $order->selling_org_uuid,
            // Null unless EVERY line has been frozen: a partially settled order is
            // not a smaller commission, it is an order somebody should look at.
            'commission_minor' => $lines->isNotEmpty() && $lines->every(
                static fn (OrderLine $line): bool => $line->commission_minor !== null,
            )
                ? (int) $lines->sum('commission_minor')
                : null,
        ];
    }

    /**
     * Who ships it, and what it is called (Shipping.md §10).
     *
     * THE STATUS COMES BACK AS A STRING, not the enum — `->value` explicitly,
     * because `status` is a cast attribute and handing back the enum is the exact
     * mistake this class already made once (`orderStatus()`, 2026-08-04).
     *
     * @return array{order_number: string, selling_org_uuid: string, customer_id: int, status: string}|null
     */
    public function orderFulfilment(string $orderUuid): ?array
    {
        $order = Order::query()->where('uuid', $orderUuid)->first();

        if ($order === null) {
            return null;
        }

        return [
            'order_number' => $order->order_number,
            'selling_org_uuid' => $order->selling_org_uuid,
            // Who receives the parcel — the internal id, because it is compared
            // against the authenticated actor and never leaves the application.
            'customer_id' => $order->customer_id,
            'status' => $order->status->value,
        ];
    }

    /**
     * Paid orders, newest first — the backfill's read (Shipping.md §10).
     *
     * CAPPED BY CONTRACT, not by the caller's discipline. @see the port for why.
     *
     * @return array<int, string>
     */
    public function paidOrders(?string $sellerOrgUuid = null, int $limit = 500): array
    {
        return Order::query()
            ->where('status', OrderStatus::Paid->value)
            ->when(
                $sellerOrgUuid !== null,
                static fn ($query) => $query->where('selling_org_uuid', $sellerOrgUuid),
            )
            ->latest('id')
            ->limit($limit)
            ->pluck('uuid')
            ->all();
    }

    /**
     * @return array{items_total_minor: int, tax_total_minor: int, grand_total_minor: int, currency_code: string}|null
     */
    public function orderTotals(string $orderUuid): ?array
    {
        $order = Order::query()->with('currency')->where('uuid', $orderUuid)->first();

        if ($order === null) {
            return null;
        }

        return [
            // Minor units plus a code (non-negotiable #6). Rendering it as a
            // decimal string is the caller's presentation concern.
            'items_total_minor' => $order->items_total_minor,
            /*
            | The tax as it was EXTRACTED AT PLACEMENT, not recomputed. The rate
            | that produced it is frozen on the lines (ADR-053/055) and may since
            | have been revised, so recomputing is not guaranteed to reproduce the
            | invoice — which is the one thing this number has to do.
            */
            'tax_total_minor' => $order->tax_total_minor,
            'grand_total_minor' => $order->grand_total_minor,
            'currency_code' => $order->currency->code,
        ];
    }

    public function activeCartTotalFor(string $customerUuid): int
    {
        $cart = Cart::query()->with('items')->where('customer_uuid', $customerUuid)->first();

        if ($cart === null || $cart->items->isEmpty()) {
            return 0;
        }

        /*
        | PRICED THROUGH THE SAME SERVICE CHECKOUT USES, not a second sum. Two
        | places that add up a basket are two places for them to disagree, and the
        | one the shopper is charged by is the one that must win.
        */
        return (int) app(CartPricingService::class)->price($cart)['items_total_minor'];
    }

    /**
     * @return array<int, array{order_uuid: string, customer_uuid: string, paid_minor: int, currency_code: string, delivered_at: string}>
     */
    public function pointsEligibleSellerOrders(CarbonInterface $asOf): array
    {
        /*
        | THE RETURN WINDOW IS SHIPPING'S SETTING, READ NOT COPIED. An operator who
        | lengthens it from the panel must lengthen the wait for points too — a
        | second number here would drift and pay for sales still inside their own
        | return window.
        */
        $returnDays = max(0, (int) settings('shipping.return_days', (int) config('shipping.windows.return_days', 14)));

        $delivered = $this->shipments->deliveredBefore($asOf->copy()->subDays($returnDays));

        if ($delivered === []) {
            return [];
        }

        $rows = [];

        foreach (array_chunk(array_keys($delivered), 2_000) as $chunk) {
            $orders = Order::query()
                ->with('currency')
                ->whereIn('uuid', $chunk)
                /*
                | **`Delivered` AND NOTHING ELSE.** A refund moves the order to
                | `Refunded` and a cancellation to `Cancelled`, so the one status is
                | the whole "not undone" test — there is no separate flag anybody
                | can forget to check.
                */
                ->where('status', OrderStatus::Delivered->value)
                ->get();

            foreach ($orders as $order) {
                $rows[] = [
                    'order_uuid' => $order->uuid,
                    'customer_uuid' => $order->customer_uuid,
                    /*
                    | What the buyer actually paid for THIS seller's half of the
                    | basket, KDV included — the grand total is that number
                    | (ADR-053). Read rather than recomputed, so Phase 2's
                    | points-funded discount needs no change here.
                    */
                    'paid_minor' => (int) $order->grand_total_minor,
                    'currency_code' => $order->currency->code,
                    'delivered_at' => (string) $delivered[$order->uuid],
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public function bestSellingProductUuids(int $limit): array
    {
        /*
        | THE QUERY BUILDER, NOT ELOQUENT. This is an aggregate over two tables
        | that returns a list of strings; hydrating models to throw them away
        | would be the expensive way to reach the same array.
        */
        /** @var array<int, string> $uuids */
        $uuids = DB::table('order_lines')
            ->select('order_lines.product_uuid')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->whereIn('orders.status', self::SOLD_STATUSES)
            ->groupBy('order_lines.product_uuid')
            // UNITS, NOT LINES. Ten of one thing in a single basket outsells one
            // of another, and a "best seller" that counted baskets would rank a
            // cheap add-on above the thing people actually stock up on.
            ->orderByRaw('sum(order_lines.quantity) desc')
            ->orderBy('order_lines.product_uuid')
            ->limit(max(1, $limit))
            ->pluck('order_lines.product_uuid')
            ->all();

        return $uuids;
    }

    /**
     * @return array<int, string>
     */
    public function coPurchasedProductUuids(string $productUuid, int $limit): array
    {
        /*
        | THE BASKETS THIS PRODUCT WAS PAID FOR IN. `checkout_group_uuid` rather
        | than the order, because one basket becomes one order per seller
        | (ADR-052) — reading a single order would miss every pair bought from two
        | shops at once, which on a marketplace is most of them.
        */
        $groups = DB::table('orders')
            ->whereIn('status', self::SOLD_STATUSES)
            ->whereIn('id', DB::table('order_lines')->select('order_id')->where('product_uuid', $productUuid))
            ->distinct()
            ->pluck('checkout_group_uuid')
            ->all();

        if ($groups === []) {
            return [];
        }

        /** @var array<int, string> $uuids */
        $uuids = DB::table('order_lines')
            ->select('order_lines.product_uuid')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->whereIn('orders.checkout_group_uuid', $groups)
            ->whereIn('orders.status', self::SOLD_STATUSES)
            // A product is not its own recommendation.
            ->where('order_lines.product_uuid', '!=', $productUuid)
            ->groupBy('order_lines.product_uuid')
            /*
            | DISTINCT BASKETS, NOT UNITS. "Bought together" is a question about
            | how many PEOPLE paired the two, so three units in one basket count
            | once — otherwise a single bulk order invents a trend.
            */
            ->orderByRaw('count(distinct orders.checkout_group_uuid) desc')
            ->orderByRaw('max(order_lines.id) desc')
            ->limit(max(1, $limit))
            ->pluck('order_lines.product_uuid')
            ->all();

        return $uuids;
    }
}
