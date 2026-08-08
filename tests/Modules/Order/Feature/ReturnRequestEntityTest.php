<?php

declare(strict_types=1);

use App\Modules\Order\Domain\Enums\ReturnRequestStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\ReturnRequest;

/*
|--------------------------------------------------------------------------
| R1 — the ReturnRequest entity (ADR-073)
|--------------------------------------------------------------------------
|
| **A RETURN IS NOT A CANCELLATION, AND THE ENUM IS WHERE THAT SHOWS.** A
| pre-shipment cancellation ends when the seller answers — the goods never left.
| A return is approved while the buyer still HAS the goods, so `Approved` is the
| middle of the story and `Completed` is the end. Everything below is that one
| difference, pinned.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

it('counts an approved return as still open, which the cancellation never had to', function (): void {
    /*
     * **THE ASSERTION THE WHOLE ENUM EXISTS FOR.** An approved return is a buyer
     * walking to the cargo desk: the parcel is in flight, the money has not
     * moved, and a second return request for that order is a mistake rather than
     * a new intention. `CancellationRequestStatus::isOpen()` is true for one case
     * because a cancellation has no equivalent middle.
     */
    expect(ReturnRequestStatus::Requested->isOpen())->toBeTrue()
        ->and(ReturnRequestStatus::Approved->isOpen())->toBeTrue()
        ->and(ReturnRequestStatus::Rejected->isOpen())->toBeFalse()
        ->and(ReturnRequestStatus::Completed->isOpen())->toBeFalse();

    // And the two endings are the two terminal ones — an answered return is not
    // re-answerable.
    expect(ReturnRequestStatus::Rejected->isTerminal())->toBeTrue()
        ->and(ReturnRequestStatus::Completed->isTerminal())->toBeTrue()
        ->and(ReturnRequestStatus::Approved->isTerminal())->toBeFalse();
});

it('gives every case a colour and a translated label', function (): void {
    foreach (ReturnRequestStatus::cases() as $case) {
        expect($case->color())->not->toBeEmpty();

        // A missing lang key renders as the key itself, which is how a
        // half-translated enum reaches production.
        expect($case->label())->not->toContain('enums.ReturnRequestStatus');
    }
});

it('round-trips the line quantities it was handed', function (): void {
    $order = Order::factory()->create();

    $request = ReturnRequest::factory()
        ->forOrder($order)
        ->lines(['line-uuid-a' => 2, 'line-uuid-b' => 1])
        ->create();

    $fresh = $request->fresh();

    /*
     * THE PAYLOAD IS THE POINT. These quantities are handed whole to Payment's
     * port, which re-checks them against `payment_refund_lines` — so what comes
     * back out has to be exactly what went in, keys included. A json column that
     * quietly reindexed to a list would send Payment a set of quantities with no
     * lines attached.
     */
    expect($fresh->line_quantities)->toBe(['line-uuid-a' => 2, 'line-uuid-b' => 1])
        ->and($fresh->totalQuantity())->toBe(3)
        ->and($fresh->status)->toBe(ReturnRequestStatus::Requested)
        ->and($fresh->isOpen())->toBeTrue();
});

it('reaches its order by uuid', function (): void {
    $order = Order::factory()->create();
    $request = ReturnRequest::factory()->forOrder($order)->create();

    expect($request->order?->uuid)->toBe($order->uuid)
        // The customer is carried on the row for ownership scoping — a query
        // must never have to join through the order to answer "is this mine".
        ->and($request->customer_id)->toBe($order->customer_id);
});

it('scopes to the returns still running', function (): void {
    $order = Order::factory()->create();

    ReturnRequest::factory()->forOrder($order)->requested()->create();
    ReturnRequest::factory()->forOrder($order)->approved()->create();
    ReturnRequest::factory()->forOrder($order)->rejected()->create();
    ReturnRequest::factory()->forOrder($order)->completed()->create();

    // TWO, not one: `open` means "this return is not finished", and an approved
    // one is not.
    expect(ReturnRequest::query()->forOrder($order->uuid)->open()->count())->toBe(2)
        ->and(ReturnRequest::query()->forOrder($order->uuid)->count())->toBe(4);
});

it('stamps the seller’s shipping instructions only once approved', function (): void {
    $order = Order::factory()->create();

    $fresh = ReturnRequest::factory()->forOrder($order)->requested()->create();
    $approved = ReturnRequest::factory()->forOrder($order)->approved()->create();

    /*
     * A RETURN CODE BEFORE APPROVAL WOULD BE THE PLATFORM ANSWERING FOR THE
     * SELLER. The code is whatever the merchant's own carrier contract calls it;
     * nothing can invent one on their behalf.
     */
    expect($fresh->return_code)->toBeNull()
        ->and($fresh->decided_at)->toBeNull()
        ->and($approved->return_code)->not->toBeNull()
        ->and($approved->decided_at)->not->toBeNull()
        // AND STILL NO MONEY: `completed_at` is the moment the refund fired, and
        // approval is not it.
        ->and($approved->completed_at)->toBeNull();
});
