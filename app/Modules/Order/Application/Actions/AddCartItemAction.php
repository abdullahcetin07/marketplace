<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\OfferQueryContract;
use App\Modules\Order\Domain\Contracts\CartRepositoryContract;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\CartItem;

/**
 * Put a seller's listing in the basket (§2.1).
 *
 * IT VALIDATES THE OFFER AND COPIES ITS IDENTITY, NOT ITS PRICE. The four uuids
 * that come back — variant, product, selling org, store — are what checkout later
 * groups and reserves on, and they cannot go stale because uuids do not change. A
 * price would, which is exactly why one is not stored (§2.1).
 *
 * THE OFFER IS VALIDATED THROUGH THE CORE CONTRACT, and `activeOfferByUuid()`
 * applies the same eligibility as the buy box — so a shopper can never add
 * something the platform would not have shown them. A paused offer, a suspended
 * one, a closed shop and a sold-out shelf all come back as null and produce one
 * refusal: enumerating a seller's internal state to a buyer leaks how the platform
 * works without helping them.
 *
 * AVAILABILITY IS CHECKED, BUT NOTHING IS RESERVED. Adding to a basket must not
 * hold another shopper's stock — a cart is not a claim, and a platform where
 * browsing consumed inventory would sell nothing. The real arbitration happens at
 * CHECKOUT (ADR-054), which is why this check is advisory and the one there is
 * not. A customer may still lose the race between the two, and that is the honest
 * behaviour rather than a bug.
 *
 * ADDING THE SAME OFFER TWICE RAISES THE QUANTITY. Two lines for one thing is a
 * basket a customer cannot reason about, and a checkout that would reserve twice
 * against one (org, variant) pool.
 *
 * @see docs/modules/Order.md §2.1, §3.1
 */
final class AddCartItemAction extends BaseAction
{
    public function __construct(
        private readonly CartRepositoryContract $carts,
        private readonly OfferQueryContract $offers,
    ) {}

    public function handle(mixed ...$arguments): CartItem
    {
        /** @var int $customerId */
        $customerId = $arguments[0];
        /** @var string $customerUuid */
        $customerUuid = $arguments[1];
        /** @var AddCartItemDTO $data */
        $data = $arguments[2];

        $this->guardQuantity($data->quantity);

        $offer = $this->offers->activeOfferByUuid($data->offerUuid);

        if ($offer === null) {
            throw OrderException::offerNotSellable($data->offerUuid);
        }

        $cart = $this->carts->firstOrCreateForCustomer($customerId, $customerUuid);
        $existing = $this->carts->findItemForOffer($cart, $data->offerUuid);

        if ($existing !== null) {
            // Adding again is "make it more", not "add a second line".
            $quantity = $existing->quantity + $data->quantity;
            $this->guardQuantity($quantity);

            $existing->forceFill(['quantity' => $quantity])->save();

            return $existing;
        }

        /*
        | The line limit is checked HERE rather than before the offer lookup: a
        | full basket that is being topped up (the branch above) must still work,
        | and only a genuinely NEW line grows it.
        */
        $maxLines = (int) config('order.cart.max_lines', 100);

        if ($cart->items()->count() >= $maxLines) {
            throw OrderException::cartIsFull($maxLines);
        }

        $item = new CartItem;
        $item->fill([
            'cart_id' => $cart->getKey(),
            'offer_uuid' => $data->offerUuid,
            /*
            | DENORMALIZED IDENTITY (§2.1), taken from the offer rather than from
            | the client: a payload that could name its own variant or seller could
            | put a cheap offer's uuid on an expensive product's line.
            */
            'variant_uuid' => (string) $offer['variant_uuid'],
            'product_uuid' => (string) $offer['product_uuid'],
            'selling_org_uuid' => (string) $offer['selling_org_uuid'],
            'store_uuid' => (string) $offer['store_uuid'],
            'quantity' => $data->quantity,
        ]);
        $item->save();

        return $item;
    }

    /**
     * Guard rails, not an availability check — Inventory owns that (§3.1). This
     * catches a fat-fingered 1000 and an attempt to hold a seller's entire stock,
     * before anything else runs.
     */
    private function guardQuantity(int $quantity): void
    {
        $max = (int) config('order.cart.max_quantity_per_line', 100);

        if ($quantity < 1 || $quantity > $max) {
            throw OrderException::invalidQuantity($quantity, $max);
        }
    }
}
