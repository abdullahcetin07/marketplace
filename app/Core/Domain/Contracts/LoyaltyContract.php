<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * Spending points — the platform's fourth Core COMMAND port (ADR-084).
 *
 * **A COMMAND, NOT A QUESTION**, like Inventory's reservation (ADR-049) and Order's
 * cancellation and return (ADR-065/073). Payment tells Loyalty to earmark, spend or
 * give back; Loyalty answers whether it could. It has to be synchronous for the same
 * reason those are: a shopper pressing "öde" must be told, in that request, that
 * their balance moved under them — an event would tell them tomorrow.
 *
 * **NEITHER SIDE IMPORTS THE OTHER.** Payment calls this; Loyalty implements it;
 * `LayeringTest` fails the build on a class from either crossing over.
 *
 * **THE THREE-STEP SHAPE IS INVENTORY'S, AND ON PURPOSE.** `hold` earmarks,
 * `commit` spends, `release` gives back — the same lifecycle a reservation has,
 * because the problem is the same: a shopper occupies something at the start of a
 * payment that may never complete, and the thing must come back on its own if it
 * does not.
 *
 * **A HOLD IS TRANSIENT AND WRITES NO LEDGER ROW.** The ledger is append-only and
 * is the record of what HAPPENED; a hold is a claim on what might. Writing holds to
 * it would make the balance a sum of intentions, and an abandoned checkout would
 * need a compensating row to undo a spend that never occurred.
 *
 * @see App\Core\Domain\Contracts\InventoryReservationContract — the shape this follows
 * @see docs/modules/Loyalty.md §5
 */
interface LoyaltyContract
{
    /**
     * What these points would be worth against this cart. Pure — no state moves.
     *
     * **CLAMPED TWICE, AND BOTH CLAMPS MATTER.** To the live balance, because a
     * customer cannot spend what they do not have; and to the cart total, because
     * change is not given in cash. Asking for more than either is not an error —
     * the storefront's slider is allowed to be optimistic — it simply spends less.
     *
     * **NO CAP ON THE FRACTION** (owner's call, 2026-08-15): points may cover the
     * whole cart. The platform absorbs the discount and every seller is still paid
     * in full, so `payable` reaching zero is a supported outcome rather than an
     * edge to be prevented.
     *
     * @return array{points_applied: int, discount_minor: int, payable_minor: int, max_points: int}
     */
    public function quote(
        string $customerUuid,
        int $cartTotalMinor,
        ?int $requestedPoints = null,
        /*
        | **THE GROUP'S OWN HOLD IS NOT SUBTRACTED FROM ITS OWN QUOTE.** A caller
        | that has already earmarked points for this checkout — the pay step does,
        | before it prices the charge — would otherwise be quoted against a balance
        | those very points have already left, and the discount would come back
        | zero. Null for a pure preview, where no hold exists yet.
        */
        ?string $checkoutGroupUuid = null,
    ): array;

    /**
     * Earmark points for this checkout group, against the live balance.
     *
     * **IDEMPOTENT PER GROUP, AND THAT IS WHAT MAKES A RETRY SAFE.** A shopper who
     * refreshes the payment page, or a client that retries a timed-out request,
     * re-holds the same points rather than stacking a second claim on the same
     * balance.
     *
     * @return int the points actually held, after clamping
     */
    public function hold(string $customerUuid, int $points, string $checkoutGroupUuid): int;

    /**
     * Spend the held points: ONE `−points` row, keyed to the group.
     *
     * Idempotent, because the PayTR callback is retried (ADR-060) and the same
     * success may arrive three times. Committing without a hold is a no-op rather
     * than an error — there is nothing to spend.
     *
     * @return int the points committed (0 when there was nothing held)
     */
    public function commit(string $checkoutGroupUuid): int;

    /**
     * Drop the hold. **No ledger row** — nothing was spent, so nothing is recorded.
     */
    public function release(string $checkoutGroupUuid): void;

    /**
     * Give back points a refund undid: a `+points` row keyed to the refund.
     *
     * **A REVERSAL IS A NEW ROW, NEVER A DELETION** — the ledger is append-only, and
     * "what did I spend on that order" must stay answerable after the refund.
     *
     * `$fraction` is the refunded share of the group (1.0 for a full refund). A
     * partial refund re-credits the FLOOR of the proportion, and never more than
     * was committed: rounding a customer up on every partial refund is a way to
     * mint points out of arithmetic.
     *
     * @return int the points re-credited
     */
    public function reverse(string $checkoutGroupUuid, string $cause, float $fraction = 1.0): int;
}
