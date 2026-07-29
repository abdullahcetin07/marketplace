<?php

declare(strict_types=1);

use App\Modules\Offer\Application\Listeners\SyncOfferSearchIndex;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Events\OfferCreated;
use App\Modules\Offer\Domain\Events\OfferPriceChanged;
use App\Modules\Offer\Domain\Events\OfferStockChanged;
use App\Modules\Offer\Domain\Events\OfferWithdrawn;
use App\Modules\Offer\Domain\Models\Offer;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Offer — search indexing (§10)
|--------------------------------------------------------------------------
|
| `SCOUT_DRIVER=null` in the suite (phpunit.xml), so none of this reaches a
| cluster. What it pins is everything that is OURS: WHICH offers belong in the
| index, WHAT the document contains, and that the events which change buyability
| are actually wired.
|
| The document shape matters more than it looks. It is a public read surface —
| a client reads these fields — so a leaked internal id here is the same defect
| as one in an API response.
|
| The most load-bearing assertion is a negative: the document carries NO catalog
| text. Copying a title into this index would be the ADR-037 stale-copy problem
| wearing a different hat, and a renamed product would disagree with every offer
| document until something happened to touch it.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

it('indexes only offers a buyer could actually order', function (): void {
    expect(Offer::factory()->make()->shouldBeSearchable())->toBeTrue();

    // Sold out: still Active, still not buyable (ADR-043).
    expect(Offer::factory()->outOfStock()->make()->shouldBeSearchable())->toBeFalse();

    foreach ([OfferStatus::Paused, OfferStatus::Suspended, OfferStatus::Withdrawn] as $status) {
        expect(Offer::factory()->status($status)->make()->shouldBeSearchable())->toBeFalse();
    }
});

it('writes a document of commercial facts and identifiers only', function (): void {
    $offer = Offer::factory()->priced(12_990)->create(['stock_quantity' => 4]);

    $document = $offer->toSearchableArray();

    expect(array_keys($document))->toBe([
        'id',
        'product_uuid',
        'variant_uuid',
        'selling_org_uuid',
        'store_uuid',
        'price_minor',
        'currency_id',
        'in_stock',
        'status',
        'created_at',
    ]);

    expect($document['id'])->toBe($offer->uuid)
        // Sorting and range filtering happen here, so the integer stays —
        // it becomes a decimal string at the resource boundary and nowhere else.
        ->and($document['price_minor'])->toBeInt()->toBe(12_990)
        ->and($document['in_stock'])->toBeTrue();
});

it('never puts an internal id in the document', function (): void {
    $offer = Offer::factory()->create();

    $document = $offer->toSearchableArray();

    // Non-negotiable #7. `id` is the UUID; the internal key is not present at
    // all, under any name.
    expect($document['id'])->toBe($offer->uuid)
        ->and($document['id'])->not->toBe((string) $offer->getKey())
        ->and($document)->not->toHaveKey('selling_org_id');
});

it('carries no catalog text — that index owns the language, this one owns the commerce', function (): void {
    $document = Offer::factory()->create()->toSearchableArray();

    // A buyer query matches text against Catalog's index and price against
    // this one, joined on `product_uuid`. Copying the title here would go
    // stale the first time a product was renamed.
    foreach (['title', 'brand', 'category', 'description', 'sku'] as $catalogField) {
        expect($document)->not->toHaveKey($catalogField);
    }

    expect($document)->toHaveKey('product_uuid');
});

it('maps every field it writes, and nothing it does not', function (): void {
    $offer = Offer::factory()->create();

    // A field written without a mapping gets dynamic typing, which for a price
    // or a status is unpredictable — and the in-stock filter depends on
    // `in_stock` really being a boolean.
    expect(array_keys($offer->searchableMapping()))
        ->toBe(array_keys($offer->toSearchableArray()));

    expect($offer->searchableMapping()['price_minor']['type'])->toBe('long')
        ->and($offer->searchableMapping()['in_stock']['type'])->toBe('boolean')
        ->and($offer->searchableMapping()['product_uuid']['type'])->toBe('keyword');
});

it('indexes into its own index, separate from the catalog’s', function (): void {
    expect(Offer::factory()->make()->searchableAs())->toBe('offers');
});

/*
|--------------------------------------------------------------------------
| The wiring — what breaks silently if nobody asserts it
|--------------------------------------------------------------------------
*/

it('subscribes to every event that changes whether an offer is buyable', function (): void {
    foreach ([OfferCreated::class, OfferPriceChanged::class, OfferStockChanged::class, OfferWithdrawn::class] as $event) {
        expect(Event::hasListeners($event))->toBeTrue("no listener for {$event}");
    }
});

it('subscribes to the store lifecycle by class-string', function (): void {
    // The third buy-box condition lives in Store and reaches the index through
    // these. Subscribed by NAME because Offer imports no module — so nothing
    // but this test would notice if the wiring were dropped.
    foreach ([
        'App\Modules\Store\Domain\Events\StoreSuspended',
        'App\Modules\Store\Domain\Events\StoreReinstated',
        'App\Modules\Store\Domain\Events\StorePaused',
        'App\Modules\Store\Domain\Events\StoreActivated',
        'App\Modules\Store\Domain\Events\StoreClosed',
        'App\Modules\Store\Domain\Events\StoreArchived',
    ] as $event) {
        expect(Event::hasListeners($event))->toBeTrue("no listener for {$event}");
    }
});

it('re-evaluates a whole store’s offers when its state changes, and ignores a payload without one', function (): void {
    $offer = Offer::factory()->forStore('store-uuid')->create();

    $listener = app(SyncOfferSearchIndex::class);

    // With the null driver these are no-ops at the engine; what is asserted is
    // that the listener resolves, reads `storeUuid` off an untyped payload and
    // completes — the class-string subscription's one runtime risk.
    $listener->onStoreLifecycleChanged(new class('store-uuid')
    {
        public function __construct(public readonly string $storeUuid) {}
    });

    // A payload with no storeUuid at all must be ignored, not fatal: this
    // listener is reached by name, so a wrong event class is a live
    // possibility rather than a compile error.
    $listener->onStoreLifecycleChanged(new class {});

    expect($offer->fresh()->store_uuid)->toBe('store-uuid');
});
