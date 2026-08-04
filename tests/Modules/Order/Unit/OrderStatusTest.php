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
        ->and(OrderStatus::Refunded->isTerminal())->toBeTrue();
});

it('lets a paid order be refunded, and nothing else', function (): void {
    /*
     * "CANCEL" AFTER PAYMENT MEANS REFUND, which is a different operation with a
     * PSP call behind it — so the only edge out of `Paid` is the one Payment's P5
     * drives, and it is not `Cancelled`.
     */
    expect(OrderStatus::Paid->transitions())->toBe([OrderStatus::Refunded])
        ->and(OrderStatus::Paid->canTransitionTo(OrderStatus::Cancelled))->toBeFalse()
        // Un-refunding would mean charging the customer again, which is a new
        // payment rather than a state change.
        ->and(OrderStatus::Refunded->transitions())->toBe([]);
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
        ->and(OrderStatus::Refunded->isCancellableByCustomer())->toBeFalse();
});

it('has exactly the cases the platform can actually reach', function (): void {
    /*
     * THE RULE IS UNCHANGED AND TWO MORE CASES NOW PASS IT (2026-08-04). This file
     * used to assert three cases and name `Paid` among the states "that belong to
     * a module that does not exist". Payment now exists: its verified success
     * callback sets `Paid`, and its P5 refund sets `Refunded`. Each arrived with
     * the phase that can actually set it, not before.
     *
     * `Preparing`, `Shipped`, `Delivered` and `Completed` are still absent for
     * exactly the original reason: Shipping does not exist, so those would be
     * cases nothing can ever set, and the first reader would reasonably assume
     * something does.
     */
    expect(array_map(fn (OrderStatus $s): string => $s->value, OrderStatus::cases()))
        ->toBe(['pending', 'awaiting_payment', 'paid', 'refunded', 'cancelled']);
});

it('gives every case a colour for the panels', function (): void {
    foreach (OrderStatus::cases() as $status) {
        expect($status->color())->toBeIn(['warning', 'info', 'success', 'gray', 'danger']);
    }
});
