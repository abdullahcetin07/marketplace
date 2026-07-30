<?php

declare(strict_types=1);

use App\Modules\Inventory\Domain\Enums\ReservationStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Modules\Inventory\Domain\Models\StockItem;

/*
|--------------------------------------------------------------------------
| What the stock record DERIVES rather than stores
|--------------------------------------------------------------------------
|
| No database: availability and the low-stock line are computations on an
| unsaved model, which is the point. `available` is `on_hand − reserved`
| (ADR-048) and is never a column — storing it would be a third number to keep in
| step with two that already move independently.
|
| The low-stock rule's edge cases carry more weight than they look: a null
| threshold and a zero threshold mean opposite things, and treating them alike is
| the obvious mistake.
|
*/

/**
 * An unsaved stock pool with just enough state to compute against.
 */
function pool(int $onHand, int $reserved = 0, ?int $threshold = null): StockItem
{
    return (new StockItem)->forceFill([
        'on_hand' => $onHand,
        'reserved' => $reserved,
        'low_stock_threshold' => $threshold,
    ]);
}

it('computes availability as on-hand minus reserved', function (): void {
    expect(pool(10)->available())->toBe(10)
        ->and(pool(10, 3)->available())->toBe(7)
        ->and(pool(10, 10)->available())->toBe(0);
});

it('never reports negative availability', function (): void {
    // The invariants stop `reserved` exceeding `on_hand`, but a negative number
    // rendered on a storefront would be a worse bug than a wrong zero, and the
    // clamp costs nothing.
    expect(pool(2, 5)->available())->toBe(0);
});

it('answers whether a quantity can be sold', function (): void {
    $item = pool(10, 7);

    expect($item->isAvailable())->toBeTrue()
        ->and($item->isAvailable(3))->toBeTrue()
        ->and($item->isAvailable(4))->toBeFalse()
        // Asking for nothing is not a question about stock.
        ->and($item->isAvailable(0))->toBeFalse()
        ->and($item->isAvailable(-1))->toBeFalse();
});

it('reads availability, not on-hand, when deciding sellability', function (): void {
    // Ten in the warehouse and all of them spoken for is NOT in stock. This one
    // assertion is the whole reason Inventory exists between the seller's
    // number and the buy box.
    expect(pool(10, 10)->isAvailable())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The low-stock line
|--------------------------------------------------------------------------
*/

it('says nothing when the seller set no threshold', function (): void {
    // Silence is the correct answer for a seller who never asked to be told.
    expect(pool(0)->isLowStock())->toBeFalse()
        ->and(pool(1000)->isLowStock())->toBeFalse();
});

it('treats zero as a real threshold, not as "unset"', function (): void {
    // "Tell me when I actually run out" is a legitimate request, which is why
    // every check tests against null rather than falsiness.
    expect(pool(0, threshold: 0)->isLowStock())->toBeTrue()
        ->and(pool(1, threshold: 0)->isLowStock())->toBeFalse();
});

it('flags at or below the threshold, on AVAILABILITY not on-hand', function (): void {
    expect(pool(6, threshold: 5)->isLowStock())->toBeFalse()
        ->and(pool(5, threshold: 5)->isLowStock())->toBeTrue()
        ->and(pool(4, threshold: 5)->isLowStock())->toBeTrue();

    // Ten on the shelf, seven held: three sellable. The seller wants to hear
    // about that, and a rule reading on-hand would stay silent.
    expect(pool(10, 7, threshold: 5)->isLowStock())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The enums
|--------------------------------------------------------------------------
*/

it('knows which movement types actually move on-hand', function (): void {
    // Reserving and releasing move nothing physical — that is the entire
    // distinction reservations exist to draw, and the ledger records it.
    expect(StockMovementType::SellerAdjustment->movesOnHand())->toBeTrue()
        ->and(StockMovementType::Committed->movesOnHand())->toBeTrue()
        ->and(StockMovementType::Reserved->movesOnHand())->toBeFalse()
        ->and(StockMovementType::Released->movesOnHand())->toBeFalse();
});

it('treats both endings of a reservation as terminal', function (): void {
    // Terminal is what makes release and commit idempotent: a repeat call finds
    // a reservation that is no longer active and does nothing.
    expect(ReservationStatus::Active->isActive())->toBeTrue()
        ->and(ReservationStatus::Active->isTerminal())->toBeFalse()
        ->and(ReservationStatus::Released->isTerminal())->toBeTrue()
        ->and(ReservationStatus::Committed->isTerminal())->toBeTrue();
});

it('gives every enum case a colour for the panels', function (): void {
    foreach (StockMovementType::cases() as $type) {
        expect($type->color())->toBeString();
        expect($type->color())->not->toBeEmpty();
    }

    foreach (ReservationStatus::cases() as $status) {
        expect($status->color())->toBeString();
        expect($status->color())->not->toBeEmpty();
    }
});
