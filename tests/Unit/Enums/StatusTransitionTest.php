<?php

declare(strict_types=1);

use App\Shared\Enums\OfferStatus;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\StoreStatus;

/*
| State machines declared as enums. Testing them here — with no database and no
| module — is the point of declaring the transitions on the enum rather than
| scattering them through services.
*/

it('permits only declared store transitions', function (): void {
    expect(StoreStatus::Pending->canTransitionTo(StoreStatus::UnderReview))->toBeTrue()
        ->and(StoreStatus::Pending->canTransitionTo(StoreStatus::Active))->toBeFalse()
        ->and(StoreStatus::UnderReview->canTransitionTo(StoreStatus::Approved))->toBeTrue()
        ->and(StoreStatus::Approved->canTransitionTo(StoreStatus::Active))->toBeTrue();
});

it('treats a closed store as terminal', function (): void {
    expect(StoreStatus::Closed->isTerminal())->toBeTrue()
        ->and(StoreStatus::Closed->allowedTransitions())->toBe([]);

    foreach (StoreStatus::cases() as $target) {
        expect(StoreStatus::Closed->canTransitionTo($target))->toBeFalse();
    }
});

it('lets only an active store sell', function (): void {
    foreach (StoreStatus::cases() as $status) {
        expect($status->canSell())->toBe($status === StoreStatus::Active);
    }
});

it('treats a withdrawn offer as terminal', function (): void {
    expect(OfferStatus::Withdrawn->allowedTransitions())->toBe([]);
});

it('keeps out-of-stock offers searchable but not purchasable', function (): void {
    expect(OfferStatus::OutOfStock->isSearchable())->toBeTrue()
        ->and(OfferStatus::OutOfStock->isPurchasable())->toBeFalse()
        ->and(OfferStatus::Active->isPurchasable())->toBeTrue();
});

it('only exposes published products publicly', function (): void {
    foreach (ProductStatus::cases() as $status) {
        expect($status->isPublic())->toBe($status === ProductStatus::Published);
    }
});

it('accepts offers on published and unpublished products only', function (): void {
    expect(ProductStatus::Published->acceptsOffers())->toBeTrue()
        ->and(ProductStatus::Unpublished->acceptsOffers())->toBeTrue()
        ->and(ProductStatus::Archived->acceptsOffers())->toBeFalse()
        ->and(ProductStatus::Draft->acceptsOffers())->toBeFalse();
});

it('gives every case a colour', function (): void {
    foreach ([...StoreStatus::cases(), ...OfferStatus::cases(), ...ProductStatus::cases()] as $case) {
        expect($case->color())->not->toBeEmpty();
    }
});
