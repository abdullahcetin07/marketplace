<?php

declare(strict_types=1);

use App\Modules\Offer\Application\Jobs\ZeroOffersMissingFromFeedJob;
use App\Modules\Offer\Application\Listeners\SweepOffersMissingFromFeed;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Offer\Presentation\Filament\Seller\Imports\OfferImporter;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| "Bu liste mağazamın tamamı" — the full-sync sweep
|--------------------------------------------------------------------------
|
| A seller uploads their live price list per store and expects the shop to match
| it: what the file does not carry, they no longer hold. The sweep zeroes STOCK
| and nothing else — the listing, its price and its history stay.
|
| Two guards are worth more than the feature: it only runs when the seller ticked
| the box, and it refuses to run on an empty sighting list. A file whose rows all
| failed, or a barcode column mapped to the wrong header, describes the entire
| shop as "missing" — and the honest answer to no evidence is to do nothing.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * @return array{org: Organization, store: Store, seller: App\Models\Seller}
 */
function fullSyncShop(): array
{
    /** @var App\Models\Seller $seller */
    $seller = App\Models\Seller::factory()->create();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    return ['org' => $organization, 'store' => $store, 'seller' => $seller];
}

/**
 * @param array{org: Organization, store: Store, seller: App\Models\Seller} $shop
 */
function fullSyncOffer(array $shop, int $stock = 7, OfferStatus $status = OfferStatus::Active): Offer
{
    return Offer::factory()
        ->forOrganization($shop['org']->getKey(), $shop['org']->uuid)
        ->forStore($shop['store']->uuid)
        ->create(['stock_quantity' => $stock, 'status' => $status]);
}

/**
 * @param array{org: Organization, store: Store, seller: App\Models\Seller} $shop
 */
function fullSyncImport(array $shop): Import
{
    /** @var Import $import */
    $import = Import::query()->create([
        'user_id' => $shop['seller']->getKey(),
        'file_name' => 'liste.csv',
        'file_path' => 'imports/liste.csv',
        'importer' => OfferImporter::class,
        'total_rows' => 1,
        'processed_rows' => 1,
        'successful_rows' => 1,
    ]);

    return $import;
}

function sighted(Import $import, string $variantUuid): void
{
    DB::table('offer_feed_seen_variants')->insert([
        'import_id' => $import->getKey(),
        'variant_uuid' => $variantUuid,
        'created_at' => now(),
    ]);
}

it('zeroes the stock of what the file never mentioned, and leaves the rest', function (): void {
    $shop = fullSyncShop();

    $inTheFile = fullSyncOffer($shop, stock: 7);
    $missing = fullSyncOffer($shop, stock: 12);

    $import = fullSyncImport($shop);
    sighted($import, $inTheFile->variant_uuid);

    app(ZeroOffersMissingFromFeedJob::class, ['importId' => $import->getKey()])
        ->handle(app(App\Modules\Offer\Application\Import\SellerFeedIdentity::class),
            app(App\Modules\Offer\Application\Actions\UpdateOfferStockAction::class));

    expect($missing->refresh()->stock_quantity)->toBe(0)
        // The listing itself is untouched: price, status and place stay.
        ->and($missing->status)->toBe(OfferStatus::Active)
        ->and($inTheFile->refresh()->stock_quantity)->toBe(7);
});

it('mirrors the zero into Inventory, because it drives the action', function (): void {
    $shop = fullSyncShop();

    $missing = fullSyncOffer($shop, stock: 9);
    $import = fullSyncImport($shop);
    // A sighting of something else, so the list is not empty.
    sighted($import, (string) Illuminate\Support\Str::uuid());

    app(ZeroOffersMissingFromFeedJob::class, ['importId' => $import->getKey()])
        ->handle(app(App\Modules\Offer\Application\Import\SellerFeedIdentity::class),
            app(App\Modules\Offer\Application\Actions\UpdateOfferStockAction::class));

    // The availability authority is what the buy box reads (ADR-048); a mass
    // UPDATE would have left this at nine.
    $available = app(App\Core\Domain\Contracts\InventoryQueryContract::class)
        ->availableFor($missing->variant_uuid, $shop['org']->uuid);

    expect($available)->toBe(0);
});

it('does nothing at all when the file was never seen', function (): void {
    $shop = fullSyncShop();

    $untouched = fullSyncOffer($shop, stock: 5);
    $import = fullSyncImport($shop);

    // No sightings: every row failed, or the barcode column was mapped wrong.
    app(ZeroOffersMissingFromFeedJob::class, ['importId' => $import->getKey()])
        ->handle(app(App\Modules\Offer\Application\Import\SellerFeedIdentity::class),
            app(App\Modules\Offer\Application\Actions\UpdateOfferStockAction::class));

    expect($untouched->refresh()->stock_quantity)->toBe(5);
});

it("never reaches another company's shelf", function (): void {
    $mine = fullSyncShop();
    $theirs = fullSyncShop();

    $theirOffer = fullSyncOffer($theirs, stock: 4);
    $import = fullSyncImport($mine);
    sighted($import, (string) Illuminate\Support\Str::uuid());

    app(ZeroOffersMissingFromFeedJob::class, ['importId' => $import->getKey()])
        ->handle(app(App\Modules\Offer\Application\Import\SellerFeedIdentity::class),
            app(App\Modules\Offer\Application\Actions\UpdateOfferStockAction::class));

    expect($theirOffer->refresh()->stock_quantity)->toBe(4);
});

it('leaves a suspended offer alone — that one is not the seller’s to move', function (): void {
    $shop = fullSyncShop();

    $suspended = fullSyncOffer($shop, stock: 3, status: OfferStatus::Suspended);
    $import = fullSyncImport($shop);
    sighted($import, (string) Illuminate\Support\Str::uuid());

    app(ZeroOffersMissingFromFeedJob::class, ['importId' => $import->getKey()])
        ->handle(app(App\Modules\Offer\Application\Import\SellerFeedIdentity::class),
            app(App\Modules\Offer\Application\Actions\UpdateOfferStockAction::class));

    expect($suspended->refresh()->stock_quantity)->toBe(3);
});

it('clears its sightings once the sweep is done', function (): void {
    $shop = fullSyncShop();
    fullSyncOffer($shop, stock: 2);
    $import = fullSyncImport($shop);
    sighted($import, (string) Illuminate\Support\Str::uuid());

    app(ZeroOffersMissingFromFeedJob::class, ['importId' => $import->getKey()])
        ->handle(app(App\Modules\Offer\Application\Import\SellerFeedIdentity::class),
            app(App\Modules\Offer\Application\Actions\UpdateOfferStockAction::class));

    expect(DB::table('offer_feed_seen_variants')->where('import_id', $import->getKey())->count())->toBe(0);
});

it('queues the sweep only for a ticked offer-feed import', function (array $options, string $importer, bool $queued): void {
    Bus::fake();

    $shop = fullSyncShop();
    $import = fullSyncImport($shop);
    $import->forceFill(['importer' => $importer])->save();

    app(SweepOffersMissingFromFeed::class)->handle(
        new Filament\Actions\Imports\Events\ImportCompleted($import, [], $options),
    );

    $queued
        ? Bus::assertDispatched(ZeroOffersMissingFromFeedJob::class)
        : Bus::assertNotDispatched(ZeroOffersMissingFromFeedJob::class);
})->with([
    'ticked' => [['zero_missing' => true], OfferImporter::class, true],
    'not ticked' => [['zero_missing' => false], OfferImporter::class, false],
    'option absent' => [[], OfferImporter::class, false],
    'another importer' => [['zero_missing' => true], 'App\Modules\Catalog\Presentation\Filament\Imports\ProductImporter', false],
]);
