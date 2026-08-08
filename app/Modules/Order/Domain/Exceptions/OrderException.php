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
     * The buyer may not ask for this order to be cancelled (ADR-065, C2).
     *
     * ONE ANSWER FOR "not paid", "already shipped" AND "no parcel on record",
     * because they are the same answer to the buyer — this is not something you
     * can ask for right now — and telling them apart would let anyone learn
     * whether a stranger's parcel had left by watching which error came back.
     *
     * IT IS ALSO WHAT AN APPROVAL HITS when the parcel shipped while the request
     * sat unanswered: the seller is refused for the same reason the buyer would
     * have been, out of the same method, so the two cannot drift.
     */
    public static function notCancellableByRequest(string $orderUuid): self
    {
        return self::make('This order can no longer be cancelled — it may already be on its way.')
            ->withContext([
                'reason' => 'not_cancellable_by_request',
                'order_uuid' => $orderUuid,
            ])
            ->withStatus(Response::HTTP_CONFLICT);
    }

    /**
     * A second request while one is still in front of the seller (ADR-065, C2).
     *
     * A REFUSAL RATHER THAN A SILENT NO-OP returning the open row. The two are
     * indistinguishable to the buyer and only one of them is true: their request
     * is already waiting. Silence would read as "sent again", and a buyer who
     * believes they have nudged somebody has been misled by the software.
     */
    public static function cancellationAlreadyRequested(string $orderUuid): self
    {
        return self::make('A cancellation request for this order is already awaiting an answer.')
            ->withContext([
                'reason' => 'cancellation_already_requested',
                'order_uuid' => $orderUuid,
            ])
            ->withStatus(Response::HTTP_CONFLICT);
    }

    /**
     * Answering a request that has already been answered (ADR-065, C2).
     *
     * REFUSED RATHER THAN IGNORED, because the two answers do opposite things:
     * approving a rejected request would refund an order the seller decided to
     * fulfil, and rejecting an approved one would claim a sale proceeds when its
     * money has already gone back.
     */
    public static function cancellationAlreadyDecided(string $requestUuid): self
    {
        return self::make('This cancellation request has already been answered.')
            ->withContext([
                'reason' => 'cancellation_already_decided',
                'request_uuid' => $requestUuid,
            ])
            ->withStatus(Response::HTTP_CONFLICT);
    }

    /**
     * A return of something that cannot come back (ADR-073).
     *
     * ONE ANSWER FOR SEVERAL QUESTIONS — never delivered, nothing left on that
     * line, three of two asked for, an empty request. The alternative lets a
     * buyer map an order's history by watching which refusal changes, and the
     * seller's screen is where the honest detail belongs.
     */
    public static function notReturnable(string $orderUuid): self
    {
        return self::make('These items can no longer be returned.')
            ->withContext([
                'reason' => 'not_returnable',
                'order_uuid' => $orderUuid,
            ])
            ->withStatus(Response::HTTP_CONFLICT);
    }

    /**
     * The return window has closed, or the parcel never arrived (ADR-073).
     *
     * DELIBERATELY ONE ANSWER FOR BOTH. Only a delivery opens a window (S3), so
     * "not delivered" and "too late" are the same fact seen from two sides — and
     * a buyer probing the difference learns nothing they are owed.
     */
    public static function returnWindowClosed(string $orderUuid): self
    {
        return self::make('The return period for this order has ended.')
            ->withContext([
                'reason' => 'return_window_closed',
                'order_uuid' => $orderUuid,
            ])
            ->withStatus(Response::HTTP_CONFLICT);
    }

    /**
     * A second return while one is still running (ADR-073).
     *
     * **"RUNNING" INCLUDES APPROVED**, which is where this differs from the
     * cancellation's equivalent: an approved return is a buyer walking to the
     * cargo desk with a code in hand, and a second request for that order is a
     * mistake rather than a new intention.
     */
    public static function returnAlreadyRequested(string $orderUuid): self
    {
        return self::make('A return for this order is already in progress.')
            ->withContext([
                'reason' => 'return_already_requested',
                'order_uuid' => $orderUuid,
            ])
            ->withStatus(Response::HTTP_CONFLICT);
    }

    /**
     * Answering a return that has already been answered (ADR-073).
     *
     * REFUSED RATHER THAN IGNORED. Re-approving would replace a return code the
     * buyer is holding a printout of; rejecting an approved one would withdraw
     * permission for a parcel that may already be in transit.
     */
    public static function returnAlreadyDecided(string $requestUuid): self
    {
        return self::make('This return request has already been answered.')
            ->withContext([
                'reason' => 'return_already_decided',
                'request_uuid' => $requestUuid,
            ])
            ->withStatus(Response::HTTP_CONFLICT);
    }

    /**
     * Completing a return the seller never approved (ADR-073).
     *
     * THE BUYER HAS NO WAY TO HAVE SENT ANYTHING BACK. Completion refunds, and
     * refunding a parcel still in the buyer's hallway is precisely the trust
     * ADR-073 removed from the flow.
     */
    public static function returnNotApproved(string $requestUuid): self
    {
        return self::make('This return has not been approved yet.')
            ->withContext([
                'reason' => 'return_not_approved',
                'request_uuid' => $requestUuid,
            ])
            ->withStatus(Response::HTTP_CONFLICT);
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
