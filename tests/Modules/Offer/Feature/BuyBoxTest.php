<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\OfferQueryContract;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| The buy box — computed, never stored (ADR-045, §5)
|--------------------------------------------------------------------------
|
| The single most consequential read in the module: it decides which seller gets
| the sale. What is pinned here is the RULE, not the plumbing —
|
|   eligible = Active + in stock + on an Active store
|   featured = cheapest eligible, ties by earliest created_at
|
| — and every exclusion, because each one is a different way for a seller to
| lose a sale they should have won, or to win one they should not have.
|
| The test acts on the Core contract, never on the Offer module directly. That
| is how Order, Search and the storefront will read it, so it is how this proves
| it works.
|
| These tests DO touch Store: this is the seam where Offer's own rows meet
| Store's liveness. The tests may import both — the arch rules bind the app, not
| the suite, which is exactly what lets a test verify a boundary from outside it.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A live store to hang offers on, returned as its uuid — which is all Offer
 * ever holds.
 */
function liveStore(): string
{
    return Store::factory()->create(['status' => StoreStatus::Active])->uuid;
}

/**
 * The same product offered by several sellers — the situation the buy box
 * exists for.
 *
 * @return array<string, Offer>
 */
function offersForOneProduct(string $productUuid, string $storeUuid): array
{
    return [
        'expensive' => Offer::factory()->priced(19_990)->forVariant('v-1', $productUuid)
            ->forStore($storeUuid)->create(),
        'cheap' => Offer::factory()->priced(12_990)->forVariant('v-2', $productUuid)
            ->forStore($storeUuid)->create(),
        'mid' => Offer::factory()->priced(15_990)->forVariant('v-3', $productUuid)
            ->forStore($storeUuid)->create(),
    ];
}

it('features the cheapest offer and lists the rest ascending', function (): void {
    $store = liveStore();
    $offers = offersForOneProduct('p-1', $store);

    $query = app(OfferQueryContract::class);

    expect($query->featuredOfferForProduct('p-1')['uuid'])->toBe($offers['cheap']->uuid);

    expect(array_column($query->activeOffersForProduct('p-1'), 'uuid'))->toBe([
        $offers['cheap']->uuid,
        $offers['mid']->uuid,
        $offers['expensive']->uuid,
    ]);
});

it('breaks a price tie by the earliest offer', function (): void {
    $store = liveStore();

    $first = Offer::factory()->priced(9_990)->forVariant('v-1', 'p-tie')->forStore($store)
        ->create(['created_at' => now()->subDays(3)]);
    $second = Offer::factory()->priced(9_990)->forVariant('v-2', 'p-tie')->forStore($store)
        ->create(['created_at' => now()->subDay()]);

    // Whoever listed at that price first keeps it — a stable rule a seller can
    // be told, which "whichever row the database returns" is not.
    $featured = app(OfferQueryContract::class)->featuredOfferForProduct('p-tie');

    expect($featured['uuid'])->toBe($first->uuid);
    expect($featured['uuid'])->not->toBe($second->uuid);
});

it('excludes an out-of-stock offer even though it is still Active', function (): void {
    $store = liveStore();

    $soldOut = Offer::factory()->priced(1_000)->outOfStock()
        ->forVariant('v-1', 'p-2')->forStore($store)->create();
    $available = Offer::factory()->priced(9_990)
        ->forVariant('v-2', 'p-2')->forStore($store)->create();

    $query = app(OfferQueryContract::class);

    // The cheapest row in the table does NOT win — this is the derivation
    // (ADR-043) doing its job at the only place it matters commercially.
    expect($soldOut->status)->toBe(OfferStatus::Active)
        ->and($query->featuredOfferForProduct('p-2')['uuid'])->toBe($available->uuid)
        ->and(array_column($query->activeOffersForProduct('p-2'), 'uuid'))
        ->not->toContain($soldOut->uuid);
});

it('excludes paused, suspended and withdrawn offers', function (): void {
    $store = liveStore();

    $paused = Offer::factory()->priced(1_000)->paused()->forVariant('v-1', 'p-3')->forStore($store)->create();
    $suspended = Offer::factory()->priced(2_000)->suspended()->forVariant('v-2', 'p-3')->forStore($store)->create();
    $withdrawn = Offer::factory()->priced(3_000)->status(OfferStatus::Withdrawn)
        ->forVariant('v-3', 'p-3')->forStore($store)->create();
    $withdrawn->delete();

    $live = Offer::factory()->priced(9_990)->forVariant('v-4', 'p-3')->forStore($store)->create();

    $uuids = array_column(app(OfferQueryContract::class)->activeOffersForProduct('p-3'), 'uuid');

    expect($uuids)->toBe([$live->uuid])
        ->and($uuids)->not->toContain($paused->uuid)
        ->and($uuids)->not->toContain($suspended->uuid)
        ->and($uuids)->not->toContain($withdrawn->uuid);
});

it('excludes an offer whose store is not live — the cross-context condition', function (): void {
    $liveStore = liveStore();
    $suspendedStore = Store::factory()->create(['status' => StoreStatus::Suspended])->uuid;

    $cheapButDark = Offer::factory()->priced(1_000)
        ->forVariant('v-1', 'p-4')->forStore($suspendedStore)->create();
    $sellable = Offer::factory()->priced(9_990)
        ->forVariant('v-2', 'p-4')->forStore($liveStore)->create();

    // Offer's own columns say the dark offer is perfectly sellable. The third
    // eligibility condition belongs to Store, and it is the one that decides.
    expect($cheapButDark->isBuyBoxEligible())->toBeTrue()
        ->and(app(OfferQueryContract::class)->featuredOfferForProduct('p-4')['uuid'])
        ->toBe($sellable->uuid);
});

it('returns null when nothing on a product is sellable', function (): void {
    $store = liveStore();
    Offer::factory()->outOfStock()->forVariant('v-1', 'p-5')->forStore($store)->create();

    // The product exists in the catalog and has a seller; nobody can buy it.
    // Null, not an exception and not an empty-ish featured row.
    expect(app(OfferQueryContract::class)->featuredOfferForProduct('p-5'))->toBeNull()
        ->and(app(OfferQueryContract::class)->activeOffersForProduct('p-5'))->toBe([]);
});

it('answers the same question for one variant', function (): void {
    $store = liveStore();

    $wanted = Offer::factory()->priced(5_000)->forVariant('sku-red-m', 'p-6')->forStore($store)->create();
    Offer::factory()->priced(1_000)->forVariant('sku-blue-l', 'p-6')->forStore($store)->create();

    // A cart line resolves a SKU, not a product (ADR-039) — the cheaper offer
    // on a different variant must not leak into the answer.
    expect(array_column(app(OfferQueryContract::class)->activeOffersForVariant('sku-red-m'), 'uuid'))
        ->toBe([$wanted->uuid]);
});

it('never leaks an internal id and carries money as minor units', function (): void {
    $store = liveStore();
    Offer::factory()->priced(12_990, 19_990)->forVariant('v-1', 'p-7')->forStore($store)->create();

    $row = app(OfferQueryContract::class)->featuredOfferForProduct('p-7');

    expect($row)->not->toHaveKey('id')
        ->and($row)->not->toHaveKey('selling_org_id')
        ->and($row)->not->toHaveKey('currency_id')
        ->and($row['price_minor'])->toBeInt()->toBe(12_990)
        ->and($row['list_price_minor'])->toBe(19_990)
        ->and($row['currency_code'])->toBeString()
        ->and($row['in_stock'])->toBeTrue();
});

it('reports a withdrawn offer as no longer existing', function (): void {
    $store = liveStore();
    $offer = Offer::factory()->forVariant('v-1', 'p-8')->forStore($store)->create();

    $query = app(OfferQueryContract::class);
    expect($query->offerExists($offer->uuid))->toBeTrue();

    $offer->delete();

    // The row survives for a future order line; nothing may start selling from
    // it again.
    expect($query->offerExists($offer->uuid))->toBeFalse();
});

it('lists a store’s own offers for the storefront', function (): void {
    $mine = liveStore();
    $theirs = liveStore();

    $ours = Offer::factory()->forVariant('v-1', 'p-9')->forStore($mine)->create();
    $notOurs = Offer::factory()->forVariant('v-2', 'p-9')->forStore($theirs)->create();

    $uuids = array_column(app(OfferQueryContract::class)->offersForStore($mine), 'uuid');

    expect($uuids)->toBe([$ours->uuid])
        ->and($uuids)->not->toContain($notOurs->uuid);
});
