<?php

declare(strict_types=1);

use App\Modules\Shipping\Domain\Enums\DeliveredVia;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;

/*
|--------------------------------------------------------------------------
| Where a parcel can go (ADR-063/064)
|--------------------------------------------------------------------------
|
| The state machine is small and one edge in it is the module's whole reason for
| existing: `shipped → delivered` is what releases a seller's money, and the
| seller must never be the one to walk it.
|
*/

it('lets a pending parcel be shipped or cancelled, and nothing else', function (): void {
    /*
     * `Cancelled` JOINED THIS ROW IN ADR-065 (2026-08-06) and it is the gate
     * itself: a paid order may be cancelled while the parcel is still here and
     * never after, so `Shipped` deliberately has no way back. A missing edge
     * cannot be forgotten the way a status check somewhere else can.
     */
    expect(ShipmentStatus::Pending->transitions())
        ->toBe([ShipmentStatus::Shipped, ShipmentStatus::Cancelled])
        // Not straight to delivered: a parcel nobody handed to a carrier cannot
        // have arrived.
        ->and(ShipmentStatus::Pending->canTransitionTo(ShipmentStatus::Delivered))->toBeFalse()
        // AND THE GATE, FROM THE OTHER SIDE: once it is with a carrier the
        // seller has spent the effort, and the buyer's route is the return.
        ->and(ShipmentStatus::Shipped->canTransitionTo(ShipmentStatus::Cancelled))->toBeFalse()
        ->and(ShipmentStatus::Delivered->canTransitionTo(ShipmentStatus::Cancelled))->toBeFalse()
        ->and(ShipmentStatus::Cancelled->transitions())->toBe([]);
});

it('lets a shipped parcel only be delivered', function (): void {
    /*
     * DELIVERY IS INFERRED (ADR-064) — the buyer confirms or the transit window
     * elapses — but either way this is the only edge out of transit. There is no
     * "lost" or "returned to sender" state in v1, and inventing one nothing can
     * set would be the mistake `OrderStatus` avoided for `Paid`.
     */
    expect(ShipmentStatus::Shipped->transitions())->toBe([ShipmentStatus::Delivered])
        ->and(ShipmentStatus::Shipped->canTransitionTo(ShipmentStatus::Returned))->toBeFalse();
});

it('treats a return as terminal, and reachable only from delivered', function (): void {
    // A return is Payment's refund reaching back here (S4), not a lever in this
    // module — and a returned parcel that ships again is a new order.
    expect(ShipmentStatus::Delivered->transitions())->toBe([ShipmentStatus::Returned])
        ->and(ShipmentStatus::Returned->transitions())->toBe([]);
});

it('asks "can the seller still hand this over" in one place', function (): void {
    /*
     * The panel, the action and the policy all read this. If they each decided
     * for themselves, a seller would eventually see a button that then refuses
     * them.
     */
    expect(ShipmentStatus::Pending->isAwaitingHandover())->toBeTrue()
        ->and(ShipmentStatus::Shipped->isAwaitingHandover())->toBeFalse()
        ->and(ShipmentStatus::Delivered->isAwaitingHandover())->toBeFalse()
        ->and(ShipmentStatus::Returned->isAwaitingHandover())->toBeFalse();

    // The transit sweep's question (S2), asked the same way.
    expect(ShipmentStatus::Shipped->isInTransit())->toBeTrue()
        ->and(ShipmentStatus::Pending->isInTransit())->toBeFalse();
});

it('has exactly the cases the platform can reach', function (): void {
    /*
     * `returned` IS HERE BEFORE ANYTHING SETS IT, which this platform normally
     * refuses to do. The reason is the one `PaymentStatus` gave for its refund
     * cases: it is reachable within THIS module's own phases from a state S1
     * already writes, and the transition table above is what these tests assert
     * against. An enum whose terminal state claims to be terminal, when the next
     * phase makes it not, has to be corrected rather than extended.
     */
    expect(array_map(fn (ShipmentStatus $s): string => $s->value, ShipmentStatus::cases()))
        ->toBe(['pending', 'shipped', 'delivered', 'returned', 'cancelled']);
});

it('gives every case a colour for the panels', function (): void {
    foreach (ShipmentStatus::cases() as $status) {
        expect($status->color())->toBeIn(['warning', 'info', 'success', 'danger', 'gray']);
    }

    foreach (DeliveredVia::cases() as $via) {
        expect($via->color())->toBeIn(['warning', 'info', 'success']);
    }
});

/*
|--------------------------------------------------------------------------
| How much a delivery date is worth
|--------------------------------------------------------------------------
*/

it('records how delivery was established, and never names the seller', function (): void {
    /*
     * THE ABSENCE IS THE ASSERTION. There is no `DeliveredVia::Seller`, because
     * the seller is paid on delivery (ADR-064) — a case here would be a case
     * somebody eventually sets.
     */
    $cases = array_map(fn (DeliveredVia $v): string => $v->value, DeliveredVia::cases());

    expect($cases)->toBe(['buyer', 'transit_sweep', 'carrier']);
    expect($cases)->not->toContain('seller');
});

it('distinguishes an observed delivery from a guessed one', function (): void {
    // The question a support agent arbitrating "I never got it" is really asking.
    expect(DeliveredVia::Buyer->isObserved())->toBeTrue()
        ->and(DeliveredVia::Carrier->isObserved())->toBeTrue()
        ->and(DeliveredVia::TransitSweep->isObserved())->toBeFalse();
});
