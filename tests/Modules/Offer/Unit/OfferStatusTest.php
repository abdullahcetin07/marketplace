<?php

declare(strict_types=1);

use App\Modules\Offer\Domain\Enums\OfferStatus;

/*
|--------------------------------------------------------------------------
| OfferStatus — the lifecycle, and the two things it deliberately is not
|--------------------------------------------------------------------------
|
| Pure logic, no database. Two negatives carry most of the weight here:
|
|  1. There is NO `OutOfStock` case (ADR-043/045). Out-of-stock is derived from
|     `stock_quantity = 0`. If someone ever adds the case, the enum has two
|     sources of truth for one fact and they drift the first time a seller
|     restocks without touching status.
|  2. A seller can neither reach nor leave `Suspended`. That is an admin's
|     oversight state (ADR-044); a seller who could pause-and-resume out of it
|     would have a one-click undo for someone else's decision.
|
*/

it('has exactly four cases and no OutOfStock', function (): void {
    expect(OfferStatus::values())->toBe(['active', 'paused', 'withdrawn', 'suspended']);

    // Stated as its own assertion because the absence IS the decision.
    expect(OfferStatus::tryFrom('out_of_stock'))->toBeNull();
});

it('lets a seller pause and resume, and withdraw from either state', function (): void {
    expect(OfferStatus::Active->canSellerTransitionTo(OfferStatus::Paused))->toBeTrue()
        ->and(OfferStatus::Paused->canSellerTransitionTo(OfferStatus::Active))->toBeTrue()
        ->and(OfferStatus::Active->canSellerTransitionTo(OfferStatus::Withdrawn))->toBeTrue()
        ->and(OfferStatus::Paused->canSellerTransitionTo(OfferStatus::Withdrawn))->toBeTrue();
});

it('never lets a seller reach or leave a suspension', function (): void {
    foreach ([OfferStatus::Active, OfferStatus::Paused, OfferStatus::Withdrawn] as $from) {
        expect($from->canSellerTransitionTo(OfferStatus::Suspended))->toBeFalse();
    }

    // And from inside it, nothing at all — not even withdrawing, which would
    // erase the offer an admin is acting on.
    expect(OfferStatus::Suspended->sellerTransitions())->toBe([]);
});

it('treats withdrawal as terminal for the seller', function (): void {
    expect(OfferStatus::Withdrawn->isTerminal())->toBeTrue()
        ->and(OfferStatus::Withdrawn->sellerTransitions())->toBe([])
        ->and(OfferStatus::Active->isTerminal())->toBeFalse()
        ->and(OfferStatus::Paused->isTerminal())->toBeFalse();
});

it('lets only an active offer compete for the buy box', function (): void {
    expect(OfferStatus::Active->isBuyBoxEligible())->toBeTrue();

    foreach ([OfferStatus::Paused, OfferStatus::Withdrawn, OfferStatus::Suspended] as $status) {
        expect($status->isBuyBoxEligible())->toBeFalse();
    }
});

it('lists paused offers publicly but never suspended or withdrawn ones', function (): void {
    // A paused offer may show greyed so a buyer sees the seller exists (§5);
    // a suspended one must not, or an oversight action becomes visible to the
    // public as an ordinary "unavailable".
    expect(OfferStatus::Active->isPubliclyListable())->toBeTrue()
        ->and(OfferStatus::Paused->isPubliclyListable())->toBeTrue()
        ->and(OfferStatus::Suspended->isPubliclyListable())->toBeFalse()
        ->and(OfferStatus::Withdrawn->isPubliclyListable())->toBeFalse();
});

it('counts everything but a withdrawal against the one-offer-per-variant rule', function (): void {
    // §3.2 — a withdrawn offer must NOT block listing the variant again, which
    // is the only reason this list is not simply "all cases".
    expect(OfferStatus::blockingDuplicates())->toBe([
        OfferStatus::Active,
        OfferStatus::Paused,
        OfferStatus::Suspended,
    ]);
});

it('gives every case a colour for the panels', function (): void {
    foreach (OfferStatus::cases() as $status) {
        expect($status->color())->toBeString();
        expect($status->color())->not->toBeEmpty();
    }
});
