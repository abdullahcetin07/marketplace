<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use App\Modules\Order\Domain\Enums\OrderStatus;
use Illuminate\Http\Response;

/**
 * A cart, checkout or order operation the domain refuses.
 *
 * EXPECTED REFUSALS, NOT INCIDENTS — an empty cart, a sold-out line, an address
 * that is not yours, a double cancel. `$reportable` stays false (BaseException's
 * default): a shopper racing another shopper for the last unit is the system
 * working, not a page for somebody at 3am. Never a 500.
 *
 * EVERY ONE CARRIES A MACHINE-READABLE `reason`, because this module's refusals
 * reach an API a storefront has to react to differently for each: "sold out"
 * re-renders the basket, "address not found" re-opens a picker, and "already
 * placed" is a duplicate submit to swallow. A parsed sentence cannot carry that.
 *
 * THE SOLD-OUT ONE IS THE INTERESTING CASE. It is not an error in any meaningful
 * sense — it is the outcome of two customers wanting the same last unit, and
 * exactly what the reservation mechanism exists to arbitrate. It names the line so
 * a storefront can point at the item rather than failing the whole basket
 * anonymously, even though the checkout itself is all-or-nothing (§3.1).
 *
 * @see docs/modules/Order.md §3.1, §3.3
 */
final class OrderException extends BaseException
{
    protected int $status = Response::HTTP_UNPROCESSABLE_ENTITY;

    /**
     * §3.1 — there is nothing to check out. Caught before any reservation is
     * attempted, because an empty checkout would otherwise create a group with no
     * orders in it and look like a successful purchase.
     */
    public static function cartIsEmpty(): self
    {
        return self::make('Your basket is empty.')
            ->withContext(['reason' => 'cart_empty']);
    }

    /**
     * The offer is gone, paused, suspended or its store is closed — anything that
     * makes it not sellable right now (§3.1).
     *
     * ONE REFUSAL FOR ALL OF THEM, deliberately: from the customer's side they are
     * the same fact ("you cannot buy this"), and enumerating a seller's internal
     * state to a shopper leaks how the platform works without helping them.
     */
    public static function offerNotSellable(string $offerUuid): self
    {
        return self::make('One of the items in your basket is no longer available.')
            ->withContext(['reason' => 'offer_not_sellable', 'offer_uuid' => $offerUuid]);
    }

    /**
     * §3.1 — not enough stock, or somebody else got there first.
     *
     * ALL-OR-NOTHING: this fails the WHOLE checkout and every hold taken so far is
     * released. Partially checking out would leave a customer with half a purchase
     * they did not ask for and a seller with a hold on the other half.
     */
    public static function insufficientStock(string $offerUuid, int $requested): self
    {
        return self::make('There is not enough stock for one of the items in your basket.')
            ->withContext([
                'reason' => 'insufficient_stock',
                'offer_uuid' => $offerUuid,
                'requested' => $requested,
            ]);
    }

    /**
     * The chosen shipping or billing address does not exist, or is not this
     * customer's.
     *
     * BOTH CASES, ONE MESSAGE, on purpose: distinguishing them would tell an
     * attacker whether an address uuid they guessed is real.
     */
    public static function addressNotFound(string $addressUuid): self
    {
        return self::make('That address could not be found.')
            ->withContext(['reason' => 'address_not_found', 'address_uuid' => $addressUuid]);
    }

    /**
     * §3.3 — the order is not in a state this move is defined from.
     *
     * Carries both ends, because "cannot cancel" is not actionable while
     * "cannot cancel an order that is already cancelled" is.
     */
    public static function invalidTransition(OrderStatus $from, OrderStatus $to): self
    {
        return self::make("An order that is {$from->value} cannot become {$to->value}.")
            ->withContext([
                'reason' => 'invalid_transition',
                'from' => $from->value,
                'to' => $to->value,
            ]);
    }

    /**
     * Placing a checkout group that has already been placed, or has nothing
     * placeable left in it.
     *
     * Distinct from `invalidTransition` because it is asked of a GROUP rather than
     * one order (ADR-052) — a customer's "place my purchase" acts on N orders, and
     * a double submit must not half-place them.
     */
    public static function checkoutGroupNotPlaceable(string $checkoutGroupUuid): self
    {
        return self::make('This purchase has already been completed or is no longer valid.')
            ->withContext([
                'reason' => 'group_not_placeable',
                'checkout_group_uuid' => $checkoutGroupUuid,
            ]);
    }

    /**
     * The product carries no KDV bracket, so the line's tax cannot be computed
     * (ADR-055/056).
     *
     * SHOULD BE UNREACHABLE — a product cannot be submitted, let alone published
     * and offered, without one. It exists because the alternative to refusing is
     * guessing a tax rate on a real sale, and there is no defensible guess.
     */
    public static function missingTaxRate(string $productUuid): self
    {
        return self::make('One of the items in your basket cannot be priced for tax.')
            ->withContext(['reason' => 'missing_tax_rate', 'product_uuid' => $productUuid]);
    }

    /**
     * A cart line quantity outside the configured guard rails.
     *
     * NOT an availability check — Inventory owns that. This catches a fat-fingered
     * 1000 and an attempt to hold a seller's entire stock for free, before a
     * reservation is even attempted.
     */
    public static function invalidQuantity(int $requested, int $max): self
    {
        return self::make("You can order between 1 and {$max} of one item.")
            ->withContext(['reason' => 'invalid_quantity', 'requested' => $requested, 'max' => $max]);
    }

    /**
     * The basket has hit the configured line limit.
     */
    public static function cartIsFull(int $max): self
    {
        return self::make("A basket can hold at most {$max} different items.")
            ->withContext(['reason' => 'cart_full', 'max' => $max]);
    }
}
