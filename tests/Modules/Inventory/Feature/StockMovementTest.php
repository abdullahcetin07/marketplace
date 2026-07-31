<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Modules\Inventory\Application\Actions\AdjustStockAction;
use App\Modules\Inventory\Application\Actions\SetLowStockThresholdAction;
use App\Modules\Inventory\Domain\DTOs\AdjustStockDTO;
use App\Modules\Inventory\Domain\DTOs\SetLowStockThresholdDTO;
use App\Modules\Inventory\Domain\Enums\ReservationStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Modules\Inventory\Domain\Events\StockAdjusted;
use App\Modules\Inventory\Domain\Events\StockCommitted;
use App\Modules\Inventory\Domain\Events\StockItemCreated;
use App\Modules\Inventory\Domain\Events\StockLowStockReached;
use App\Modules\Inventory\Domain\Events\StockReleased;
use App\Modules\Inventory\Domain\Events\StockReserved;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Domain\Models\StockReservation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The three reservation primitives, and the ledger under them
|--------------------------------------------------------------------------
|
| This is the machinery Inventory exists to provide (ADR-049), and it has no live
| caller yet — Order will be the first. So these tests ARE the caller, which is
| precisely what building the authority first was supposed to buy: a contract
| whose semantics are pinned before a checkout depends on them.
|
| The assertions that matter most are the negatives and the repeats:
|
|  - reserve REFUSES at `available < qty`. Two buyers cannot both take the last
|    unit, which is the oversell no column on the Offer could have prevented.
|  - release and commit are NO-OPS on a finished hold. A retried cart timeout
|    must not hand back phantom availability; a retried order confirmation must
|    not destroy stock that physically exists.
|  - every change leaves a MOVEMENT (ADR-050). The projection's claim to be
|    correct is that it can be rebuilt from them.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A pool with a known quantity, created the way production creates one — through
 * the mirror, so the ledger accounts for every unit in it.
 */
function stockedPool(int $onHand, string $orgUuid = 'org-a', string $variantUuid = 'v-1'): StockItem
{
    return app(AdjustStockAction::class)->run(new AdjustStockDTO(
        variantUuid: $variantUuid,
        productUuid: 'p-1',
        sellingOrgId: 7,
        sellingOrgUuid: $orgUuid,
        onHand: $onHand,
        offerUuid: 'offer-1',
    ));
}

/*
|--------------------------------------------------------------------------
| The mirror
|--------------------------------------------------------------------------
*/

it('creates a pool on the first adjustment, with the units accounted for', function (): void {
    Event::fake([StockItemCreated::class, StockAdjusted::class]);

    $item = stockedPool(10);

    expect($item->on_hand)->toBe(10)
        ->and($item->reserved)->toBe(0)
        ->and($item->available())->toBe(10);

    // The pool starts EMPTY and the quantity arrives as a movement — seeding
    // on_hand directly would put units in the projection the ledger never saw.
    $movements = StockMovement::query()->where('stock_item_id', $item->getKey())->get();

    expect($movements)->toHaveCount(1)
        ->and($movements->first()->type)->toBe(StockMovementType::SellerAdjustment)
        ->and($movements->first()->on_hand_delta)->toBe(10);

    Event::assertDispatched(StockItemCreated::class);
    Event::assertDispatched(StockAdjusted::class);
});

it('records the DELTA when the seller changes their number', function (): void {
    $item = stockedPool(10);

    stockedPool(4);

    // Absolute in, delta recorded: the ledger sums to the seller's figure, and a
    // replayed event converges on it instead of compounding.
    expect($item->fresh()->on_hand)->toBe(4);

    $latest = StockMovement::query()->where('stock_item_id', $item->getKey())
        ->orderByDesc('id')->first();

    expect($latest->on_hand_delta)->toBe(-6);
});

it('records nothing when the seller’s number has not changed', function (): void {
    $item = stockedPool(10);
    stockedPool(10);

    // A zero-delta movement would be noise in the one place a seller goes to
    // understand their stock — and a replayed event is exactly how it arrives.
    expect(StockMovement::query()->where('stock_item_id', $item->getKey())->count())->toBe(1);
});

it('leaves reserved alone when the seller adjusts on-hand', function (): void {
    $item = stockedPool(10);
    app(InventoryReservationContract::class)->reserve('org-a', 'v-1', 3, 'ref-1');

    stockedPool(8);

    // A seller correcting their shelf count says nothing about units already
    // promised to a checkout.
    expect($item->fresh()->on_hand)->toBe(8)
        ->and($item->fresh()->reserved)->toBe(3)
        ->and($item->fresh()->available())->toBe(5);
});

/*
|--------------------------------------------------------------------------
| reserve
|--------------------------------------------------------------------------
*/

it('holds units without moving on-hand', function (): void {
    Event::fake([StockReserved::class]);
    $item = stockedPool(10);

    expect(app(InventoryReservationContract::class)->reserve('org-a', 'v-1', 3, 'ref-1'))->toBeTrue();

    // Nothing left the seller; the units are spoken for. That distinction is
    // the reason `reserved` is a separate number at all.
    expect($item->fresh()->on_hand)->toBe(10)
        ->and($item->fresh()->reserved)->toBe(3)
        ->and($item->fresh()->available())->toBe(7);

    $movement = StockMovement::query()->where('reference', 'ref-1')->sole();

    expect($movement->type)->toBe(StockMovementType::Reserved)
        ->and($movement->on_hand_delta)->toBe(0)
        ->and($movement->reserved_delta)->toBe(3);

    Event::assertDispatched(StockReserved::class);
});

it('refuses to reserve more than is available', function (): void {
    stockedPool(3);

    expect(fn () => app(InventoryReservationContract::class)->reserve('org-a', 'v-1', 4, 'ref-1'))
        ->toThrow(InventoryException::class);

    expect(StockReservation::query()->count())->toBe(0);
});

it('counts existing holds against a new one — the last unit goes once', function (): void {
    $reservations = app(InventoryReservationContract::class);
    $item = stockedPool(1);

    expect($reservations->reserve('org-a', 'v-1', 1, 'first'))->toBeTrue();

    /*
     * THE RACE THIS MODULE EXISTS TO PREVENT. On SQLite the writes are already
     * serialised, so what this proves is the SEQUENCE — availability is read
     * inside the lock, so the second caller sees what the first left. On
     * Postgres the row lock makes the same sequence hold under real parallelism.
     */
    expect(fn () => $reservations->reserve('org-a', 'v-1', 1, 'second'))
        ->toThrow(InventoryException::class);

    expect($item->fresh()->reserved)->toBe(1)
        ->and($item->fresh()->available())->toBe(0);
});

it('is idempotent: a repeated reserve does not take a second unit', function (): void {
    $reservations = app(InventoryReservationContract::class);
    $item = stockedPool(10);

    $reservations->reserve('org-a', 'v-1', 3, 'ref-1');
    $reservations->reserve('org-a', 'v-1', 3, 'ref-1');

    // A payment provider retrying a webhook is normal; the alternative to this
    // is overselling on a network blip.
    expect($item->fresh()->reserved)->toBe(3)
        ->and(StockReservation::query()->count())->toBe(1)
        ->and(StockMovement::query()->where('reference', 'ref-1')->count())->toBe(1);
});

it('refuses to reserve against a variant the seller never stocked', function (): void {
    // "Never listed" is a different answer from "sold out": a caller told the
    // wrong one would retry something that can never succeed.
    expect(fn () => app(InventoryReservationContract::class)->reserve('org-a', 'nope', 1, 'ref-1'))
        ->toThrow(InventoryException::class);
});

it('refuses a quantity that cannot mean anything', function (): void {
    stockedPool(10);

    expect(fn () => app(InventoryReservationContract::class)->reserve('org-a', 'v-1', 0, 'ref-1'))
        ->toThrow(InventoryException::class);
    expect(fn () => app(InventoryReservationContract::class)->reserve('org-a', 'v-1', -2, 'ref-2'))
        ->toThrow(InventoryException::class);
});

/*
|--------------------------------------------------------------------------
| release
|--------------------------------------------------------------------------
*/

it('gives a hold back, raising availability and nothing else', function (): void {
    Event::fake([StockReleased::class]);
    $reservations = app(InventoryReservationContract::class);
    $item = stockedPool(10);

    $reservations->reserve('org-a', 'v-1', 3, 'ref-1');
    $reservations->release('ref-1');

    expect($item->fresh()->on_hand)->toBe(10)
        ->and($item->fresh()->reserved)->toBe(0)
        ->and($item->fresh()->available())->toBe(10);

    expect(StockReservation::query()->where('reference', 'ref-1')->sole()->status)
        ->toBe(ReservationStatus::Released);

    Event::assertDispatched(StockReleased::class);
});

it('is idempotent: a repeated release does not hand back phantom availability', function (): void {
    $reservations = app(InventoryReservationContract::class);
    $item = stockedPool(10);

    $reservations->reserve('org-a', 'v-1', 3, 'ref-1');
    $reservations->release('ref-1');
    $reservations->release('ref-1');

    // A cart-timeout job firing twice must not lower `reserved` below what is
    // actually held.
    expect($item->fresh()->reserved)->toBe(0)
        ->and(StockMovement::query()->where('reference', 'ref-1')
            ->where('type', StockMovementType::Released->value)->count())->toBe(1);
});

it('refuses a reference nothing was ever reserved under', function (): void {
    // Distinct from acting twice on a real hold, which is a no-op. This is a
    // caller bug and worth surfacing.
    expect(fn () => app(InventoryReservationContract::class)->release('never-existed'))
        ->toThrow(InventoryException::class);
});

/*
|--------------------------------------------------------------------------
| commit
|--------------------------------------------------------------------------
*/

it('lowers both numbers when a hold becomes a sale', function (): void {
    Event::fake([StockCommitted::class]);
    $reservations = app(InventoryReservationContract::class);
    $item = stockedPool(10);

    $reservations->reserve('org-a', 'v-1', 3, 'ref-1');
    $reservations->commit('ref-1');

    // Both, by the same amount: the units go, and the hold covering them ends
    // with them. Leaving `reserved` behind would double-count the sale forever.
    expect($item->fresh()->on_hand)->toBe(7)
        ->and($item->fresh()->reserved)->toBe(0)
        ->and($item->fresh()->available())->toBe(7);

    $movement = StockMovement::query()->where('reference', 'ref-1')
        ->where('type', StockMovementType::Committed->value)->sole();

    expect($movement->on_hand_delta)->toBe(-3)
        ->and($movement->reserved_delta)->toBe(-3);

    Event::assertDispatched(StockCommitted::class);
});

it('is idempotent: a repeated commit does not destroy stock twice', function (): void {
    $reservations = app(InventoryReservationContract::class);
    $item = stockedPool(10);

    $reservations->reserve('org-a', 'v-1', 3, 'ref-1');
    $reservations->commit('ref-1');
    $reservations->commit('ref-1');

    // The most expensive bug this shape prevents: unlike a lost reservation, a
    // double commit destroys stock that physically exists.
    expect($item->fresh()->on_hand)->toBe(7)
        ->and(StockMovement::query()->where('reference', 'ref-1')
            ->where('type', StockMovementType::Committed->value)->count())->toBe(1);
});

it('will not commit a hold that was already released', function (): void {
    $reservations = app(InventoryReservationContract::class);
    $item = stockedPool(10);

    $reservations->reserve('org-a', 'v-1', 3, 'ref-1');
    $reservations->release('ref-1');
    $reservations->commit('ref-1');

    // Terminal is terminal in both directions. A cancelled checkout that later
    // "confirms" must not take units.
    expect($item->fresh()->on_hand)->toBe(10)
        ->and(StockReservation::query()->where('reference', 'ref-1')->sole()->status)
        ->toBe(ReservationStatus::Released);
});

it('keeps the whole ledger summing to the projection', function (): void {
    $reservations = app(InventoryReservationContract::class);
    $item = stockedPool(10);

    $reservations->reserve('org-a', 'v-1', 4, (string) Str::uuid());
    $held = (string) Str::uuid();
    $reservations->reserve('org-a', 'v-1', 2, $held);
    $reservations->commit($held);

    $movements = StockMovement::query()->where('stock_item_id', $item->getKey())->get();

    // ADR-050's promise, asserted: on_hand and reserved are SUM(deltas).
    expect($movements->sum('on_hand_delta'))->toBe($item->fresh()->on_hand)
        ->and($movements->sum('reserved_delta'))->toBe($item->fresh()->reserved);
});

/*
|--------------------------------------------------------------------------
| Low stock
|--------------------------------------------------------------------------
*/

it('warns once when availability crosses the line, and re-arms on the way up', function (): void {
    Event::fake([StockLowStockReached::class]);

    $item = stockedPool(10);
    app(SetLowStockThresholdAction::class)->run($item, new SetLowStockThresholdDTO(5));

    // Down to 6: still above the line.
    stockedPool(6);
    Event::assertNotDispatched(StockLowStockReached::class);

    // Down to 5: crosses.
    stockedPool(5);
    Event::assertDispatchedTimes(StockLowStockReached::class, 1);

    // Down to 4: still low, and silent — firing on every movement while stock
    // stayed low would train the seller to ignore the one that matters.
    stockedPool(4);
    Event::assertDispatchedTimes(StockLowStockReached::class, 1);

    // Back up: re-arms.
    stockedPool(20);
    expect($item->fresh()->low_stock_notified)->toBeFalse();

    // And crossing again notifies again.
    stockedPool(3);
    Event::assertDispatchedTimes(StockLowStockReached::class, 2);
});

it('warns on availability, so a reservation can trigger it', function (): void {
    Event::fake([StockLowStockReached::class]);

    $item = stockedPool(10);
    app(SetLowStockThresholdAction::class)->run($item, new SetLowStockThresholdDTO(5));

    app(InventoryReservationContract::class)->reserve('org-a', 'v-1', 6, 'ref-1');

    // Ten on the shelf, six held: four sellable. The seller wants to hear about
    // that, and a rule reading on-hand would stay silent.
    Event::assertDispatchedTimes(StockLowStockReached::class, 1);
});

it('says nothing when no threshold is set', function (): void {
    Event::fake([StockLowStockReached::class]);

    stockedPool(10);
    stockedPool(0);

    // Silence is the correct answer for a seller who never asked to be told.
    Event::assertNotDispatched(StockLowStockReached::class);
});

it('re-arms the warning when the threshold itself changes', function (): void {
    $item = stockedPool(3);
    app(SetLowStockThresholdAction::class)->run($item, new SetLowStockThresholdDTO(5));

    stockedPool(2);
    expect($item->fresh()->low_stock_notified)->toBeTrue();

    // A new line means the old notification was about something else; a stale
    // flag would silence the next real crossing.
    app(SetLowStockThresholdAction::class)->run($item->fresh(), new SetLowStockThresholdDTO(1));

    expect($item->fresh()->low_stock_notified)->toBeFalse()
        ->and($item->fresh()->low_stock_threshold)->toBe(1);
});

it('records no movement for a threshold change', function (): void {
    $item = stockedPool(10);
    $before = StockMovement::query()->where('stock_item_id', $item->getKey())->count();

    app(SetLowStockThresholdAction::class)->run($item, new SetLowStockThresholdDTO(5));

    // The ledger records changes to COUNTS. A preference is not a count, and
    // rows with two zero deltas would be noise in a seller's history.
    expect(StockMovement::query()->where('stock_item_id', $item->getKey())->count())->toBe($before);
});
