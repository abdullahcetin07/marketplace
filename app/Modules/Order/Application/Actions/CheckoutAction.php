<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Core\Domain\Contracts\CatalogQueryContract;
use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Core\Domain\Contracts\OfferQueryContract;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Order\Domain\Contracts\CartRepositoryContract;
use App\Modules\Order\Domain\Contracts\CustomerAddressRepositoryContract;
use App\Modules\Order\Domain\Contracts\OrderNumberGeneratorContract;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Events\CartCheckedOut;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\Cart;
use App\Modules\Order\Domain\Models\CartItem;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use App\Modules\Order\Domain\Support\IncludedTax;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turn a basket into orders, and HOLD the stock (ADR-052/053/054 — §3.1).
 *
 * THE CENTRE OF THE MODULE. Four decisions meet in this one transaction:
 *
 *   1. THE SPLIT (ADR-052). The cart is partitioned by selling org and each
 *      partition becomes its own `Order`, all sharing a `checkout_group_uuid`.
 *      One purchase to the customer; N independent orders to the sellers.
 *   2. THE SNAPSHOT (ADR-053). Price, title, variant label, KDV rate and both
 *      addresses are frozen onto the lines. From this moment the order does not
 *      care what the catalog or the offer says.
 *   3. THE RESERVATION (ADR-054). Order becomes the first real caller of
 *      Inventory's command contract. Nothing is sold yet — the units are HELD,
 *      on a clock.
 *   4. THE TAX (ADR-055). Extracted per line from the tax-included price using
 *      the product's bracket, in integer arithmetic.
 *
 * ALL-OR-NOTHING, AND THAT IS THE HARD PART. If any line cannot reserve, the
 * whole checkout fails and every hold taken so far goes back. Partially checking
 * out would leave a customer with half a purchase they did not ask for and a
 * seller holding stock for the other half. The rollback is BELT AND BRACES:
 * `BaseAction`'s transaction unwinds the order rows, and the explicit release
 * loop unwinds the holds — because a reservation is a row in ANOTHER module's
 * table, written through a contract, and nothing guarantees the two transactions
 * are the same one.
 *
 * THE VALIDATION IS DELIBERATELY REPEATED. `AddCartItemAction` already checked
 * the offer, and it checks again here — minutes or days may have passed, and the
 * only check that matters is the one taken at the moment of commitment. The
 * availability pre-check is likewise advisory: the authoritative one is inside
 * Inventory's row lock, and this one exists to fail fast on the common case
 * before any hold is taken.
 *
 * IT IMPORTS NO MODULE. Offer, Catalog and Inventory all arrive through Core
 * contracts; the only model it touches from elsewhere is `Currency`, the
 * platform-wide Localization exception.
 *
 * @see docs/modules/Order.md §3.1, §3.4
 */
final class CheckoutAction extends BaseAction
{
    /**
     * References held so far, so a failure can give every one of them back.
     *
     * @var array<int, string>
     */
    private array $held = [];

    /**
     * @var array<int, Order>
     */
    private array $orders = [];

    private string $checkoutGroupUuid = '';

    public function __construct(
        private readonly CartRepositoryContract $carts,
        private readonly CustomerAddressRepositoryContract $addresses,
        private readonly OfferQueryContract $offers,
        private readonly CatalogQueryContract $catalog,
        private readonly CatalogBrowseContract $browse,
        private readonly InventoryReservationContract $reservations,
        private readonly OrderNumberGeneratorContract $numbers,
    ) {}

    /**
     * @return array<int, Order>
     */
    public function handle(mixed ...$arguments): array
    {
        /** @var int $customerId */
        $customerId = $arguments[0];
        /** @var string $customerUuid */
        $customerUuid = $arguments[1];
        /** @var CheckoutDTO $data */
        $data = $arguments[2];

        $cart = $this->carts->forCustomer($customerId);

        if ($cart === null || $cart->isEmpty()) {
            // Caught before anything else: an empty checkout would otherwise
            // create a group with no orders in it and look like a purchase.
            throw OrderException::cartIsEmpty();
        }

        $shipping = $this->addressSnapshot($data->shippingAddressUuid, $customerId);
        $billing = $this->addressSnapshot($data->billingAddressUuid, $customerId);

        $this->checkoutGroupUuid = (string) Str::uuid();
        $this->held = [];
        $this->orders = [];

        try {
            foreach ($cart->itemsBySellingOrganization() as $items) {
                $this->orders[] = $this->createOrder(
                    $customerId,
                    $customerUuid,
                    $items,
                    $shipping,
                    $billing,
                );
            }
        } catch (\Throwable $exception) {
            /*
            | GIVE BACK EVERY HOLD TAKEN SO FAR, then rethrow.
            |
            | The transaction will unwind this module's rows, but a reservation
            | lives in Inventory and was written through a contract — there is no
            | guarantee it participates in the same transaction, and assuming so
            | would leave a seller's stock held by an order that does not exist.
            | Release is idempotent, so doing it twice costs nothing.
            */
            $this->releaseHeld();

            throw $exception;
        }

        // The basket's contents are now order lines. Leaving them would let the
        // same basket be checked out twice; the CART survives because the
        // customer will shop again.
        $this->carts->clear($cart);

        return $this->orders;
    }

    /**
     * One seller's partition → one `Order` (ADR-052).
     *
     * @param  Collection<int, CartItem>  $items
     * @param  array<string, string|null>  $shipping
     * @param  array<string, string|null>  $billing
     */
    private function createOrder(
        int $customerId,
        string $customerUuid,
        Collection $items,
        array $shipping,
        array $billing,
    ): Order {
        /** @var CartItem $first */
        $first = $items->first();

        $order = new Order;
        $order->fill([
            'order_number' => $this->numbers->generate(),
            'checkout_group_uuid' => $this->checkoutGroupUuid,
            'customer_id' => $customerId,
            'customer_uuid' => $customerUuid,
            'selling_org_uuid' => $first->selling_org_uuid,
            'store_uuid' => $first->store_uuid,
            // PENDING: the stock is held, nothing is placed. `AwaitingPayment` is
            // `PlaceOrderAction`'s to set, after the commit.
            'status' => OrderStatus::Pending,
            'currency_id' => $this->currencyId(),
            'shipping_address' => $shipping,
            'billing_address' => $billing,
        ]);
        $order->save();

        $titles = $this->browse->productSummaries(
            $items->pluck('product_uuid')->unique()->values()->all(),
        );
        $variants = $this->browse->variantSummaries(
            $items->pluck('variant_uuid')->unique()->values()->all(),
        );

        $itemsTotal = 0;
        $taxTotal = 0;

        foreach ($items as $item) {
            $offer = $this->offers->activeOfferByUuid($item->offer_uuid);

            if ($offer === null) {
                // Paused, suspended, withdrawn, shop closed or sold out — one
                // refusal, because from the buyer's side they are one fact.
                throw OrderException::offerNotSellable($item->offer_uuid);
            }

            $rate = $this->catalog->taxRateForProduct($item->product_uuid);

            if ($rate === null) {
                /*
                | Should be unreachable: a product cannot be submitted, let alone
                | published and offered, without a bracket (ADR-056). It is here
                | because the alternative to refusing is guessing a tax rate on a
                | real sale, and there is no defensible guess.
                */
                throw OrderException::missingTaxRate($item->product_uuid);
            }

            $unit = (int) $offer['price_minor'];
            $lineTotal = $unit * $item->quantity;
            // KDV-INCLUDED (ADR-042), so this EXTRACTS rather than adds. Per line,
            // because lines carry different rates (§3.4).
            $lineTax = IncludedTax::of($lineTotal, $rate);

            OrderLine::query()->create([
                'order_id' => $order->getKey(),
                'offer_uuid' => $item->offer_uuid,
                'variant_uuid' => $item->variant_uuid,
                'product_uuid' => $item->product_uuid,
                /*
                | THE SNAPSHOT (ADR-053). The title is read now and frozen; the
                | catalog may rename or withdraw the product tomorrow and this row
                | will still say what the customer bought. A fallback rather than a
                | failure when the catalog has no summary — an order must not be
                | blocked by a missing label.
                */
                'product_title' => (string) ($titles[$item->product_uuid]['title'] ?? '—'),
                'variant_label' => $variants[$item->variant_uuid]['label'] ?? null,
                'unit_price_minor' => $unit,
                'tax_rate' => $rate,
                'quantity' => $item->quantity,
                'line_tax_minor' => $lineTax,
                'line_total_minor' => $lineTotal,
            ]);

            $itemsTotal += $lineTotal;
            $taxTotal += $lineTax;

            $this->reserve($order, $item);
        }

        $order->forceFill([
            'items_total_minor' => $itemsTotal,
            'tax_total_minor' => $taxTotal,
            // No shipping and no discount this sprint (§3.4), so the grand total
            // IS the items total — which already includes the tax.
            'grand_total_minor' => $itemsTotal,
        ])->save();

        return $order;
    }

    /**
     * HOLD the units for one line (ADR-054).
     *
     * The reference is per LINE, not per order: an Inventory reservation is one
     * row keyed on a unique reference, so two lines sharing one would silently
     * leave the second unreserved. @see Order::reservationReferenceFor()
     *
     * The reference is recorded BEFORE the call, not after: a reserve that throws
     * midway must still be releasable, and a hold that was taken and not recorded
     * is a hold nothing will ever give back.
     */
    private function reserve(Order $order, CartItem $item): void
    {
        $reference = $order->reservationReferenceFor($item->variant_uuid);
        $this->held[] = $reference;

        $reserved = $this->reservations->reserve(
            $item->selling_org_uuid,
            $item->variant_uuid,
            $item->quantity,
            $reference,
        );

        if (! $reserved) {
            throw OrderException::insufficientStock($item->offer_uuid, $item->quantity);
        }
    }

    /**
     * Unwind every hold this checkout took.
     *
     * Failures are swallowed on purpose: this runs while another exception is
     * already on its way up, and a release that fails must not replace the real
     * reason the checkout failed. A hold that survives is picked up by the expiry
     * sweep (§3.3), which is exactly what that sweep is for.
     */
    private function releaseHeld(): void
    {
        foreach ($this->held as $reference) {
            try {
                $this->reservations->release($reference);
            } catch (\Throwable) {
                // Deliberately ignored — see above.
            }
        }

        $this->held = [];
    }

    /**
     * The chosen address, frozen (ADR-053/056).
     *
     * Looked up BY UUID AND BY OWNER, so a tampered payload naming somebody
     * else's address gets the same refusal as one naming an address that does not
     * exist — the difference would confirm an address uuid is real.
     *
     * @return array<string, string|null>
     */
    private function addressSnapshot(string $addressUuid, int $customerId): array
    {
        $address = $this->addresses->findForCustomer($addressUuid, $customerId);

        if ($address === null) {
            throw OrderException::addressNotFound($addressUuid);
        }

        return $address->toSnapshot();
    }

    /**
     * The platform default currency.
     *
     * SINGLE-CURRENCY THIS SPRINT, matching Offer (§13.1): every offer is priced
     * in the platform default, so an order is too. Read from Localization rather
     * than assumed, so the day multi-currency arrives this is the one place that
     * changes.
     */
    private function currencyId(): int
    {
        $id = Currency::query()->where('is_default', true)->value('id')
            ?? Currency::query()->value('id');

        if ($id === null) {
            // Localization is unseeded — an environment fault, not a customer
            // one. The same class of failure as `Currency::default()` throwing.
            throw new \RuntimeException('No currency is configured; cannot price an order.');
        }

        return (int) $id;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var array<int, Order> $orders */
        $orders = $result;

        if ($orders === []) {
            return;
        }

        $first = $orders[0];

        /*
        | ONE EVENT FOR THE WHOLE PURCHASE, not one per order (§6). The purchase is
        | what the customer did; `OrderPlaced` is the per-seller fact and comes
        | later, at placement.
        |
        | Dispatched AFTER COMMIT (BaseAction's `after()`), so no listener ever
        | sees a checkout that then rolled back.
        */
        CartCheckedOut::dispatch(
            $this->checkoutGroupUuid,
            (int) $first->customer_id,
            $first->customer_uuid,
            array_map(fn (Order $order): string => $order->uuid, $orders),
            array_sum(array_map(fn (Order $order): int => $order->grand_total_minor, $orders)),
            $first->currency->code,
        );
    }
}
