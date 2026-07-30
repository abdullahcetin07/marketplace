<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Repositories;

use App\Modules\Order\Domain\Contracts\CartRepositoryContract;
use App\Modules\Order\Domain\Models\Cart;
use App\Modules\Order\Domain\Models\CartItem;

/**
 * The basket's read vocabulary.
 *
 * ITEMS ARE ALWAYS EAGER LOADED, declared here rather than at each call site
 * (CLAUDE.md — strict mode makes a lazy load THROW in development). A cart with
 * no items loaded is useless: every question anyone asks it — what is in it, how
 * many sellers, what does it come to — walks the lines.
 *
 * @see App\Modules\Order\Domain\Contracts\CartRepositoryContract
 */
final class CartRepository implements CartRepositoryContract
{
    /**
     * @var list<string>
     */
    private array $with = ['items'];

    public function forCustomer(int $customerId): ?Cart
    {
        return Cart::query()->with($this->with)->forCustomer($customerId)->first();
    }

    /**
     * WRITES, so it is deliberately not what a read path calls.
     *
     * `firstOrCreate` rather than a check-then-insert: two tabs adding to an empty
     * basket at once would otherwise both see "no cart" and both insert, and the
     * unique index on `customer_id` would turn the loser into a 500 instead of a
     * second item in the same basket.
     */
    public function firstOrCreateForCustomer(int $customerId, string $customerUuid): Cart
    {
        $cart = Cart::query()->firstOrCreate(
            ['customer_id' => $customerId],
            ['customer_uuid' => $customerUuid],
        );

        return $cart->load($this->with);
    }

    public function findByUuid(string $uuid): ?Cart
    {
        return Cart::query()->with($this->with)->where('uuid', $uuid)->first();
    }

    /**
     * SCOPED TO THE CART, not looked up by uuid alone — so a customer who guesses
     * another basket's line uuid gets null rather than somebody else's item.
     */
    public function findItem(Cart $cart, string $itemUuid): ?CartItem
    {
        return $cart->items()->where('uuid', $itemUuid)->first();
    }

    public function findItemForOffer(Cart $cart, string $offerUuid): ?CartItem
    {
        return $cart->items()->where('offer_uuid', $offerUuid)->first();
    }

    public function clear(Cart $cart): void
    {
        $cart->items()->delete();

        // The in-memory copy would otherwise still hold the deleted lines, and
        // the caller that just checked out is about to dispatch an event
        // describing what is now an empty basket.
        $cart->setRelation('items', $cart->items()->getRelated()->newCollection());
    }
}
