<?php

declare(strict_types=1);

use App\Modules\Order\Domain\Enums\OrderStatus;

/*
|--------------------------------------------------------------------------
| The order lifecycle, as far as this sprint takes it (§2.5, ADR-054/057)
|--------------------------------------------------------------------------
|
| A state machine with three states, and the interesting assertions are about
| the two it deliberately does NOT have and the one distinction it must never
| lose:
|
|  - BOTH LIVE STATES HOLD STOCK since ADR-057 — placement no longer commits — so
|    cancelling either returns the units. What distinguishes them is whether the
|    checkout is still UNPLACED, because that alone is what the expiry sweep may
|    touch.
|  - `Cancelled` is terminal in both directions. "Un-cancelling" would have to
|    re-reserve units somebody else may already have bought.
|  - There is no `Paid`, `Shipped` or `Delivered` case, because there is no
|    module that could set one.
|
| No database: an enum is a value.
|
*/

it('holds stock in BOTH live states, so either can give it back', function (): void {
    /*
     * ADR-057 IN ONE ASSERTION. Placement used to commit, which meant a cancelled
     * placed order had nothing to release and its stock was simply gone — Inventory
     * has no un-commit. Now both live states hold, and cancelling either is a plain
     * release.
     */
    expect(OrderStatus::Pending->holdsReservation())->toBeTrue()
        ->and(OrderStatus::AwaitingPayment->holdsReservation())->toBeTrue()
        // A cancelled order holds nothing — it already gave its units back.
        ->and(OrderStatus::Cancelled->holdsReservation())->toBeFalse();
});

it('treats only an UNPLACED checkout as sweepable', function (): void {
    /*
     * The distinction that replaces the old one (ADR-057). Both live states hold
     * stock, but only one is an abandoned tab: a placed order holds until it is
     * paid or cancelled, however long that takes, because expiring it would cancel
     * a purchase the customer believes they have made.
     */
    expect(OrderStatus::Pending->isAwaitingPlacement())->toBeTrue()
        ->and(OrderStatus::AwaitingPayment->isAwaitingPlacement())->toBeFalse()
        ->and(OrderStatus::Cancelled->isAwaitingPlacement())->toBeFalse();
});

it('lets a pending order be placed or cancelled', function (): void {
    expect(OrderStatus::Pending->canTransitionTo(OrderStatus::AwaitingPayment))->toBeTrue()
        ->and(OrderStatus::Pending->canTransitionTo(OrderStatus::Cancelled))->toBeTrue();
});

it('lets a placed order be cancelled but never returned to pending', function (): void {
    expect(OrderStatus::AwaitingPayment->canTransitionTo(OrderStatus::Cancelled))->toBeTrue()
        /*
         * Cancelling releases the hold the order was still carrying (ADR-057).
         * Rewinding to `Pending` is a different thing entirely — the customer has
         * placed the order, and "still choosing" is not a state anybody asked to
         * return to.
         */
        ->and(OrderStatus::AwaitingPayment->canTransitionTo(OrderStatus::Pending))->toBeFalse();
});

it('treats cancellation as terminal in both directions', function (): void {
    expect(OrderStatus::Cancelled->transitions())->toBe([])
        ->and(OrderStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(OrderStatus::Cancelled->canTransitionTo(OrderStatus::Pending))->toBeFalse()
        ->and(OrderStatus::Cancelled->canTransitionTo(OrderStatus::AwaitingPayment))->toBeFalse();
});

it('says neither live state is finished with stock', function (): void {
    expect(OrderStatus::Pending->isTerminal())->toBeFalse()
        ->and(OrderStatus::AwaitingPayment->isTerminal())->toBeFalse()
        /*
         * NOR IS `Paid`, SINCE P5 (2026-08-04). It was terminal for one afternoon,
         * while the only thing that could follow a payment was nothing. A refund
         * both moves the order AND moves the stock — `restock` puts the committed
         * units back — so the question this method asks is genuinely open again.
         */
        ->and(OrderStatus::Paid->isTerminal())->toBeFalse()
        // Nor is `Delivered`: the return window is open behind it (S3/S4).
        ->and(OrderStatus::Delivered->isTerminal())->toBeFalse()
        ->and(OrderStatus::Refunded->isTerminal())->toBeTrue();
});

it('lets a paid order be delivered, refunded or cancelled — the last only through a refund', function (): void {
    /*
     * "CANCEL" AFTER PAYMENT STILL MEANS REFUND. That has not changed; what
     * changed is where the rule is kept.
     *
     * `Delivered` JOINED IT (Shipping S2, 2026-08-05). Order does not decide it:
     * Shipping infers delivery and announces it, and this module's own listener
     * moves the state.
     *
     * **`Cancelled` REJOINED IT (ADR-065, 2026-08-06)** so a pre-shipment
     * cancellation can name its outcome honestly — a parcel nobody ever packed
     * did not "come back". The edge is legal; reaching it without returning the
     * money is not, and this pair of assertions is the whole distinction:
     * `isCancellableWithoutRefund()` is what `CancelOrderAction` and
     * `OrderPolicy::cancel()` ask now, precisely because the transition table
     * stopped being able to answer it.
     */
    expect(OrderStatus::Paid->transitions())
        ->toBe([OrderStatus::Delivered, OrderStatus::Refunded, OrderStatus::Cancelled])
        ->and(OrderStatus::Paid->canTransitionTo(OrderStatus::Cancelled))->toBeTrue()
        // THE GUARD THAT REPLACED THE MISSING EDGE. Removing it re-arms a lever
        // that zeroes a seller's stock on an order the buyer has paid for.
        ->and(OrderStatus::Paid->isCancellableWithoutRefund())->toBeFalse()
        ->and(OrderStatus::Pending->isCancellableWithoutRefund())->toBeTrue()
        ->and(OrderStatus::AwaitingPayment->isCancellableWithoutRefund())->toBeTrue()
        ->and(OrderStatus::Delivered->isCancellableWithoutRefund())->toBeFalse();

    /*
     * A DELIVERED ORDER STAYS REFUNDABLE, which is the whole point of the return
     * window — and it does not go back to `Paid`: a parcel that arrived cannot
     * un-arrive.
     */
    expect(OrderStatus::Delivered->transitions())->toBe([OrderStatus::Refunded])
        ->and(OrderStatus::Delivered->canTransitionTo(OrderStatus::Paid))->toBeFalse();

    // Un-refunding would mean charging the customer again, which is a new payment
    // rather than a state change.
    expect(OrderStatus::Refunded->transitions())->toBe([]);
});

it('lets a customer walk away from either live state, this sprint', function (): void {
    /*
     * Nothing is charged and nothing has shipped, so there is no cost to the
     * seller in allowing it. This is the method that NARROWS when Payment and
     * Shipping exist — asked here rather than re-derived at each call site
     * precisely so that change lands in one place.
     */
    expect(OrderStatus::Pending->isCancellableByCustomer())->toBeTrue()
        ->and(OrderStatus::AwaitingPayment->isCancellableByCustomer())->toBeTrue()
        ->and(OrderStatus::Cancelled->isCancellableByCustomer())->toBeFalse()
        /*
         * IT NARROWED, EXACTLY AS THE COMMENT ABOVE PREDICTED. Once money has
         * changed hands, walking away is a REFUND — a different operation, with a
         * PSP call behind it and an actor who may not be the customer. And a
         * refunded order is finished in both directions.
         */
        ->and(OrderStatus::Paid->isCancellableByCustomer())->toBeFalse()
        // Nor a delivered one: the buyer has the goods, so walking away is a
        // RETURN, which goes through Payment's refund (S4).
        ->and(OrderStatus::Delivered->isCancellableByCustomer())->toBeFalse()
        ->and(OrderStatus::Refunded->isCancellableByCustomer())->toBeFalse();
});

it('has exactly the cases the platform can actually reach', function (): void {
    /*
     * THE RULE IS UNCHANGED AND THREE MORE CASES NOW PASS IT. This file used to
     * assert three cases and name `Paid` among the states "that belong to a
     * module that does not exist". Payment arrived and its callback sets `Paid`,
     * its P5 refund sets `Refunded`; Shipping's S2 arrived and its delivery
     * inference sets `Delivered`. Each landed with the phase that can actually
     * set it, never before.
     *
     * `Preparing`, `Shipped` and `Completed` are still absent, and now for a
     * sharper reason than "the module does not exist" — it does. WHERE A PARCEL
     * IS, IS A SHIPMENT'S BUSINESS: mirroring those states here would create a
     * second source of truth for one fact. `Delivered` is different because it is
     * when the ORDER is complete from the customer's side, and it is what the
     * return window and the payout clock are measured from.
     */
    expect(array_map(fn (OrderStatus $s): string => $s->value, OrderStatus::cases()))
        ->toBe(['pending', 'awaiting_payment', 'paid', 'delivered', 'refunded', 'cancelled']);
});

it('gives every case a colour for the panels', function (): void {
    foreach (OrderStatus::cases() as $status) {
        expect($status->color())->toBeIn(['warning', 'info', 'success', 'gray', 'danger']);
    }
});
