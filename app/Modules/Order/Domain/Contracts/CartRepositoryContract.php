<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Contracts;

use App\Modules\Order\Domain\Models\Cart;
use App\Modules\Order\Domain\Models\CartItem;

/**
 * Persistence port for the basket.
 *
 * `forCustomer()` IS THE ONE THAT MATTERS: a cart is read on every page of a
 * storefront session, and it is read WITH ITS ITEMS every time — strict mode
 * makes a lazy load throw, so the eager load is declared here rather than
 * remembered at each call site.
 *
 * @see App\Modules\Order\Infrastructure\Repositories\CartRepository
 */
interface CartRepositoryContract
{
    /**
     * The customer's basket, or null if they have never added anything.
     *
     * Null is an ordinary answer, not an error: most visitors have no cart.
     */
    public function forCustomer(int $customerId): ?Cart;

    /**
     * The customer's basket, created empty if they have none.
     *
     * SEPARATE FROM THE READ, deliberately: rendering a cart badge must not write
     * a row for every visitor who glances at the header. This is called only when
     * something is actually being added.
     */
    public function firstOrCreateForCustomer(int $customerId, string $customerUuid): Cart;

    public function findByUuid(string $uuid): ?Cart;

    /**
     * A line of this cart, by uuid — the shape an API `PATCH /cart/items/{uuid}`
     * needs, scoped so one customer cannot reach another's line by guessing.
     */
    public function findItem(Cart $cart, string $itemUuid): ?CartItem;

    /**
     * The line for one offer in this cart, or null.
     *
     * Adding an offer that is already in the basket raises the quantity instead of
     * creating a second line (§2.1), and this is the lookup that decides which.
     */
    public function findItemForOffer(Cart $cart, string $offerUuid): ?CartItem;

    /**
     * Empty the basket, keeping the cart itself.
     *
     * Called after a successful checkout. The CART survives because the customer
     * will shop again and a row per purchase is churn; the ITEMS go because they
     * are now order lines, and leaving them would let the same basket be checked
     * out twice.
     */
    public function clear(Cart $cart): void;
}
