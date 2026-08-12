<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Offer\Application\Actions\SyncSellerOfferAction;
use App\Modules\Offer\Application\Actions\SyncSellerStockAction;
use App\Modules\Offer\Application\Actions\WithdrawSellerOfferAction;
use App\Modules\Offer\Domain\DTOs\SyncOfferDTO;
use App\Modules\Offer\Domain\Enums\OfferFeedOutcome;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Events\OfferCreated;
use App\Modules\Offer\Domain\Events\OfferStockChanged;
use App\Modules\Offer\Domain\Exceptions\OfferFeedException;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| P1 — the feed's brain (ADR-076)
|--------------------------------------------------------------------------
|
| **THE LOAD-BEARING RULE IS THAT THE FEED DRIVES THE OFFER ACTIONS AND WRITES NO
| MODEL.** `CreateOfferAction` and `UpdateOfferStockAction` emit the events
| Inventory mirrors on-hand from (ADR-048) and search consumes; a model write here
| would produce an offer that is right in the table and invisible to availability
| and to search. So the assertions below are not about the `offers` row — they are
| about INVENTORY, which can only be right if the events fired.
|
| The second rule is that nothing happens twice: a seller pushes their whole
| catalogue every morning and most of it did not move overnight.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A published product with a GTIN, plus a seller who can list against it.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{org: Organization, store: Store, gtin: string, variant: ProductVariant}
 */
function feedFixture(string $gtin = '08690000001234', bool $published = true): array
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')
        ->when($published, fn ($f) => $f->published())
        ->create([
            'gtin' => $gtin,
            'tax_rate_id' => TaxRate::factory()->rate('0.2000')->create()->getKey(),
        ]);

    $variant = ProductVariant::factory()->for($product)->create(['is_default' => true]);

    return ['org' => $organization, 'store' => $store, 'gtin' => $gtin, 'variant' => $variant];
}

/**
 * @param array{org: Organization, store: Store, gtin: string, variant: ProductVariant} $fixture
 */
function feedItem(array $fixture, ?int $price = 12_900, ?int $stock = 12, ?int $listPrice = null): SyncOfferDTO
{
    return new SyncOfferDTO(
        sellingOrgId: (int) $fixture['org']->getKey(),
        sellingOrgUuid: $fixture['org']->uuid,
        storeUuid: $fixture['store']->uuid,
        gtin: $fixture['gtin'],
        priceMinor: $price,
        stockQuantity: $stock,
        listPriceMinor: $listPrice,
    );
}

it('creates an offer whose stock reaches INVENTORY, not just the offers table', function (): void {
    $fixture = feedFixture();

    $outcome = app(SyncSellerOfferAction::class)->run(feedItem($fixture, stock: 12));

    expect($outcome)->toBe(OfferFeedOutcome::Created)
        ->and(Offer::query()->count())->toBe(1);

    /*
     * **THE ASSERTION THE WHOLE FEATURE RESTS ON.** Inventory learns on-hand from
     * `OfferCreated`/`OfferStockChanged` (ADR-048). If this action had written the
     * Offer model directly — shorter, obvious, wrong — the row would look perfect
     * and availability would read zero, so the product would never appear on the
     * buy box.
     */
    expect(app(InventoryQueryContract::class)
        ->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(12);
});

it('updates price and stock separately, because they are separate events', function (): void {
    $fixture = feedFixture();
    $sync = app(SyncSellerOfferAction::class);

    $sync->run(feedItem($fixture, price: 12_900, stock: 12));

    Event::fake([OfferStockChanged::class, OfferCreated::class]);

    // Only the stock moved.
    expect($sync->run(feedItem($fixture, price: 12_900, stock: 7)))->toBe(OfferFeedOutcome::Updated);

    Event::assertDispatched(OfferStockChanged::class);
    Event::assertNotDispatched(OfferCreated::class);

    $offer = Offer::query()->firstOrFail();

    expect($offer->stock_quantity)->toBe(7)
        // The price was sent unchanged and must not have been "re-priced" — an
        // audit trail full of edits nobody made is worse than no audit trail.
        ->and($offer->price_minor)->toBe(12_900);
});

it('says Unchanged and emits NOTHING when the item repeats yesterday', function (): void {
    $fixture = feedFixture();
    $sync = app(SyncSellerOfferAction::class);

    $sync->run(feedItem($fixture));

    Event::fake([OfferStockChanged::class, OfferCreated::class]);

    /*
     * **THE OUTCOME THAT MAKES A DAILY FULL-CATALOGUE PUSH AFFORDABLE.** Most of
     * four thousand rows did not move overnight; re-announcing them would wake
     * Inventory and the search index for nothing, four thousand times.
     */
    expect($sync->run(feedItem($fixture)))->toBe(OfferFeedOutcome::Unchanged);

    Event::assertNothingDispatched();
});

it('refuses a barcode the published catalogue does not carry', function (): void {
    $fixture = feedFixture();

    $unknown = new SyncOfferDTO(
        sellingOrgId: (int) $fixture['org']->getKey(),
        sellingOrgUuid: $fixture['org']->uuid,
        storeUuid: $fixture['store']->uuid,
        gtin: '08699999999999',
        priceMinor: 9_900,
        stockQuantity: 5,
    );

    expect(fn () => app(SyncSellerOfferAction::class)->run($unknown))
        ->toThrow(OfferFeedException::class);

    // The feed never creates catalogue. Nothing was made, nothing was offered.
    expect(Offer::query()->count())->toBe(0)
        ->and(Product::query()->where('gtin', '08699999999999')->exists())->toBeFalse();
});

it('treats an UNPUBLISHED product exactly like an unknown barcode', function (): void {
    $fixture = feedFixture(published: false);

    try {
        app(SyncSellerOfferAction::class)->run(feedItem($fixture));
        $reason = null;
    } catch (OfferFeedException $exception) {
        $reason = $exception->reason();
    }

    /*
     * **ONE REASON FOR BOTH, DELIBERATELY.** Distinguishing them would let a
     * seller enumerate the unpublished catalogue one barcode at a time, and the
     * seller's next move is identical either way: ask the platform to add it.
     */
    expect($reason)->toBe('product_not_in_catalog')
        ->and(Offer::query()->count())->toBe(0);
});

it('will not invent a price to satisfy a stock-only push', function (): void {
    $fixture = feedFixture();

    try {
        app(SyncSellerStockAction::class)->run(feedItem($fixture, price: null, stock: 5));
        $reason = null;
    } catch (OfferFeedException $exception) {
        $reason = $exception->reason();
    }

    // "Run sync first" — a machine reason, not a silent no-op that would let a
    // seller believe a thousand SKUs went live when none did.
    expect($reason)->toBe('offer_not_found')
        ->and(Offer::query()->count())->toBe(0);
});

it('moves stock on its own once an offer exists, and mirrors it', function (): void {
    $fixture = feedFixture();
    app(SyncSellerOfferAction::class)->run(feedItem($fixture, stock: 12));

    $outcome = app(SyncSellerStockAction::class)->run(feedItem($fixture, price: null, stock: 3));

    expect($outcome)->toBe(OfferFeedOutcome::Updated)
        ->and(app(InventoryQueryContract::class)
            ->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(3);

    // The hourly push mostly repeats itself.
    expect(app(SyncSellerStockAction::class)->run(feedItem($fixture, price: null, stock: 3)))
        ->toBe(OfferFeedOutcome::Unchanged);
});

it('withdraws an offer, and a repeat withdrawal is not a failure', function (): void {
    $fixture = feedFixture();
    app(SyncSellerOfferAction::class)->run(feedItem($fixture));

    expect(app(WithdrawSellerOfferAction::class)->run(feedItem($fixture)))
        ->toBe(OfferFeedOutcome::Updated)
        /*
         * **WITHDRAWING IS A SOFT DELETE**, so the row is gone from every ordinary
         * query — `withTrashed()` is how the status is read at all, and the reason
         * the feed needed a finder of its own.
         */
        ->and(Offer::withTrashed()->firstOrFail()->status)->toBe(OfferStatus::Withdrawn);

    // Re-sending yesterday's discontinued list must not be a page of failures.
    expect(app(WithdrawSellerOfferAction::class)->run(feedItem($fixture)))
        ->toBe(OfferFeedOutcome::Unchanged);

    /*
     * AND THE SELLER MAY LIST IT AGAIN. `duplicateFor()` cannot see a withdrawn
     * offer precisely so that dropping a product is not permanent — the feed's
     * own finder is the narrow exception, used only for the idempotency check
     * above.
     */
    expect(app(SyncSellerOfferAction::class)->run(feedItem($fixture)))
        ->toBe(OfferFeedOutcome::Created);
});

it('refuses a struck-through price below the selling price', function (): void {
    $fixture = feedFixture();

    try {
        app(SyncSellerOfferAction::class)->run(feedItem($fixture, price: 12_900, listPrice: 9_900));
        $reason = null;
    } catch (OfferFeedException $exception) {
        $reason = $exception->reason();
    }

    // A "discount" that raises the price is a lie the storefront would render.
    expect($reason)->toBe('list_price_below_price')
        ->and(Offer::query()->count())->toBe(0);
});

it('refuses a zero price and a negative stock, each with its own reason', function (): void {
    $fixture = feedFixture();

    $reasons = [];

    foreach ([['price' => 0, 'stock' => 5], ['price' => 9_900, 'stock' => -1]] as $bad) {
        try {
            app(SyncSellerOfferAction::class)->run(feedItem($fixture, price: $bad['price'], stock: $bad['stock']));
        } catch (OfferFeedException $exception) {
            $reasons[] = $exception->reason();
        }
    }

    // Two distinct machine reasons, because a seller's system fixes them
    // differently.
    expect($reasons)->toBe(['invalid_price', 'invalid_stock']);
});

it('actually writes the struck-through price it was sent, and then stops', function (): void {
    $fixture = feedFixture();
    $sync = app(SyncSellerOfferAction::class);

    $sync->run(feedItem($fixture, price: 12_900, stock: 12));
    $sync->run(feedItem($fixture, price: 12_900, stock: 12, listPrice: 19_900));

    /*
     * **THE LIVE SMOKE TEST CAUGHT THIS AND NOTHING ELSE DID.** `present` speaks
     * COLUMN names — `UpdateOfferPriceAction` asks `has('list_price_minor')` —
     * and the feed was passing the DTO property name, so every struck-through
     * price a seller sent was read as "not sent" and dropped.
     */
    expect(Offer::query()->firstOrFail()->list_price_minor)->toBe(19_900);

    /*
     * The second half of the same bug, and the more expensive one: because the
     * value never landed, the next identical push saw a difference again and
     * reported `Updated` forever — re-pricing an unchanged catalogue daily and
     * writing an audit entry for an edit nobody made.
     */
    expect($sync->run(feedItem($fixture, price: 12_900, stock: 12, listPrice: 19_900)))
        ->toBe(OfferFeedOutcome::Unchanged);
});

it('refuses a price raised above a struck-through price it was not sent', function (): void {
    $fixture = feedFixture();
    $sync = app(SyncSellerOfferAction::class);

    $sync->run(feedItem($fixture, price: 12_900, stock: 12, listPrice: 19_900));

    try {
        // Only a price this time — the list price stays whatever the panel set.
        $sync->run(feedItem($fixture, price: 24_900, stock: 12));
        $reason = null;
    } catch (OfferFeedException $exception) {
        $reason = $exception->reason();
    }

    /*
     * **A MACHINE REASON, NOT A 500.** `UpdateOfferPriceAction` refuses this with
     * an `OfferException`, which the batch loop does not catch — so one such item
     * would have taken down the response for the four thousand good ones beside
     * it.
     */
    expect($reason)->toBe('list_price_below_price')
        ->and(Offer::query()->firstOrFail()->price_minor)->toBe(12_900);
});
