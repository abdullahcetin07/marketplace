<?php

declare(strict_types=1);

use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;

/*
|--------------------------------------------------------------------------
| What the Offer model DERIVES rather than stores
|--------------------------------------------------------------------------
|
| No database: these are computations on an unsaved model, which is the point —
| in-stock, buy-box eligibility and the discount percentage are read-time
| derivations (ADR-043/045), not columns. If any of them ever needs a row to
| answer, something has been stored that should not have been.
|
*/

/**
 * An unsaved offer with just enough state to compute against.
 */
function offerWith(int $stock, OfferStatus $status = OfferStatus::Active, ?int $listPrice = null): Offer
{
    return (new Offer)->forceFill([
        'price_minor' => 12_990,
        'list_price_minor' => $listPrice,
        'stock_quantity' => $stock,
        'status' => $status,
    ]);
}

it('derives out-of-stock from the quantity, never from a status', function (): void {
    expect(offerWith(3)->isInStock())->toBeTrue()
        ->and(offerWith(0)->isInStock())->toBeFalse();

    // Zero stock does NOT change the status — that is the whole design. The
    // offer stays Active and simply stops winning.
    expect(offerWith(0)->status)->toBe(OfferStatus::Active);
});

it('needs both an active status and stock to be buy-box eligible', function (): void {
    expect(offerWith(3, OfferStatus::Active)->isBuyBoxEligible())->toBeTrue()
        ->and(offerWith(0, OfferStatus::Active)->isBuyBoxEligible())->toBeFalse()
        ->and(offerWith(3, OfferStatus::Paused)->isBuyBoxEligible())->toBeFalse()
        ->and(offerWith(3, OfferStatus::Suspended)->isBuyBoxEligible())->toBeFalse();
});

it('computes the discount only when the list price is genuinely higher', function (): void {
    // 12.990 against a 19.990 list price → 35% off, floored.
    expect(offerWith(1, listPrice: 19_990)->discountPercent())->toBe(35);
});

it('advertises nothing rather than a zero-percent discount', function (): void {
    // No list price at all, and a list price equal to the price, are the same
    // thing to a buyer: there is no discount to show.
    expect(offerWith(1)->discountPercent())->toBeNull()
        ->and(offerWith(1, listPrice: 12_990)->discountPercent())->toBeNull()
        // A list price BELOW the price is invalid input the action rejects; if
        // one ever lands, render nothing rather than a negative discount.
        ->and(offerWith(1, listPrice: 9_990)->discountPercent())->toBeNull();
});

it('keeps money as integer minor units on the model', function (): void {
    $offer = offerWith(1, listPrice: 19_990);

    expect($offer->price_minor)->toBeInt()->toBe(12_990)
        ->and($offer->list_price_minor)->toBeInt()->toBe(19_990);
});
