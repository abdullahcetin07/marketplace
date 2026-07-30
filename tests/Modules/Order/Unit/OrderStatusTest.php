<?php

declare(strict_types=1);

use App\Modules\Order\Domain\Enums\OrderStatus;

/*
|--------------------------------------------------------------------------
| The order lifecycle, as far as this sprint takes it (§2.5, ADR-054)
|--------------------------------------------------------------------------
|
| A state machine with three states, and the interesting assertions are about
| the two it deliberately does NOT have and the one distinction it must never
| lose:
|
|  - `Pending` HOLDS stock; `AwaitingPayment` has COMMITTED it. Every
|    reserve/release/commit call downstream turns on that difference, and getting
|    it backwards either strands a seller's units forever or gives away stock
|    that was never taken back.
|  - `Cancelled` is terminal in both directions. "Un-cancelling" would have to
|    re-reserve units somebody else may already have bought.
|  - There is no `Paid`, `Shipped` or `Delivered` case, because there is no
|    module that could set one.
|
| No database: an enum is a value.
|
*/

it('holds stock while pending and has committed it once placed', function (): void {
    // THE DISTINCTION THE WHOLE TWO-STEP TURNS ON (ADR-054).
    expect(OrderStatus::Pending->holdsReservation())->toBeTrue()
        ->and(OrderStatus::AwaitingPayment->holdsReservation())->toBeFalse()
        // A cancelled order holds nothing either way — it already gave back
        // whichever of the two it had.
        ->and(OrderStatus::Cancelled->holdsReservation())->toBeFalse();
});

it('lets a pending order be placed or cancelled', function (): void {
    expect(OrderStatus::Pending->canTransitionTo(OrderStatus::AwaitingPayment))->toBeTrue()
        ->and(OrderStatus::Pending->canTransitionTo(OrderStatus::Cancelled))->toBeTrue();
});

it('lets a placed order be cancelled but never returned to pending', function (): void {
    expect(OrderStatus::AwaitingPayment->canTransitionTo(OrderStatus::Cancelled))->toBeTrue()
        /*
         * There is nothing to un-commit INTO: the reservation is gone, the units
         * have left, and cancelling puts them back on the shelf rather than back
         * into a hold.
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
        ->and(OrderStatus::AwaitingPayment->isTerminal())->toBeFalse();
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
        ->and(OrderStatus::Cancelled->isCancellableByCustomer())->toBeFalse();
});

it('has exactly the three cases the platform can actually reach', function (): void {
    /*
     * `Paid`, `Preparing`, `Shipped`, `Delivered`, `Completed` and `Returned` are
     * all states this platform will need, and every one belongs to a module that
     * does not exist. Shipping them now would put cases in the enum that nothing
     * can ever set — and the first reader would reasonably assume something does.
     */
    expect(array_map(fn (OrderStatus $s): string => $s->value, OrderStatus::cases()))
        ->toBe(['pending', 'awaiting_payment', 'cancelled']);
});

it('gives every case a colour for the panels', function (): void {
    foreach (OrderStatus::cases() as $status) {
        expect($status->color())->toBeIn(['warning', 'info', 'danger']);
    }
});
