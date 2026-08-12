<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Models\Seller;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Offer\Application\Import\OfferImportChunk;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Offer\Presentation\Filament\Seller\Imports\OfferImporter;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;

/*
|--------------------------------------------------------------------------
| P3 — the spreadsheet door (ADR-076)
|--------------------------------------------------------------------------
|
| Same brain as the API, so what is worth pinning here is what the CSV adds: a row
| that fails must be RECORDED and skipped, never thrown out of the chunk job.
|
| **THAT IS THE ADR-075 LESSON, PAID FOR ONCE ALREADY.** `ImportCsv` records a
| `RowImportFailedException` with its message and carries on; any other Throwable it
| logs without a message, collects, and rethrows — failing the job, which the queue
| retries, re-running the whole chunk. Five bad catalogue rows became 29,074
| attempts overnight that way. An unknown barcode in a seller's feed is ORDINARY, so
| it must never be able to do it.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A seller who can list, plus a published product. Named for this file: Pest
 * shares ONE global function namespace.
 *
 * @return array{seller: Seller, org: Organization, gtin: string, variant: ProductVariant}
 */
function importSeller(string $gtin = '08690000007777'): array
{
    /** @var Seller $seller */
    $seller = Seller::factory()->owner()->create();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create([
        'gtin' => $gtin,
        'tax_rate_id' => TaxRate::factory()->rate('0.2000')->create()->getKey(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create(['is_default' => true]);

    return ['seller' => $seller, 'org' => $organization, 'gtin' => $gtin, 'variant' => $variant];
}

/**
 * @param array{seller: Seller, org: Organization, gtin: string, variant: ProductVariant} $fixture
 */
function offerImporterFor(array $fixture): OfferImporter
{
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'teklifler.csv',
        'file_path' => 'imports/teklifler.csv',
        'importer' => OfferImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $fixture['seller']->getKey(),
    ]);

    return new OfferImporter($import, array_combine(
        ['gtin', 'fiyat', 'stok', 'liste_fiyati'],
        ['gtin', 'fiyat', 'stok', 'liste_fiyati'],
    ), []);
}

it('turns a row into an offer whose stock reaches Inventory', function (): void {
    $fixture = importSeller();

    offerImporterFor($fixture)([
        'gtin' => $fixture['gtin'],
        'fiyat' => '129,90',
        'stok' => '12',
        'liste_fiyati' => '159,90',
    ]);

    $offer = Offer::query()->firstOrFail();

    // A Turkish decimal comma, which is what Excel writes.
    expect($offer->price_minor)->toBe(12_990)
        ->and($offer->list_price_minor)->toBe(15_990)
        // AND THE FEED DROVE THE ACTIONS: Inventory can only know this if
        // `OfferCreated` fired.
        ->and(app(InventoryQueryContract::class)
            ->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(12);
});

it('records a rejected row instead of failing the job', function (): void {
    $fixture = importSeller();

    /*
     * **THE ONE LINE THAT KEEPS A BAD BARCODE FROM STORMING THE QUEUE.**
     * `RowImportFailedException` is what `ImportCsv` catches per row, logs WITH the
     * message, and carries on from. Anything else fails the job and the queue
     * re-runs the entire chunk.
     */
    expect(fn () => offerImporterFor($fixture)([
        'gtin' => '08699999999999',
        'fiyat' => '10,00',
        'stok' => '1',
        'liste_fiyati' => null,
    ]))->toThrow(RowImportFailedException::class, 'katalogda yok');

    expect(Offer::query()->count())->toBe(0);
});

it('updates rather than duplicating when the file is uploaded again', function (): void {
    $fixture = importSeller();

    $row = ['gtin' => $fixture['gtin'], 'fiyat' => '50,00', 'stok' => '5', 'liste_fiyati' => null];

    offerImporterFor($fixture)($row);
    offerImporterFor($fixture)($row);

    // ONE offer per (seller, variant) — the property that makes "fix three cells
    // and re-upload the whole sheet" the intended workflow.
    expect(Offer::query()->count())->toBe(1);

    // And a corrected file moves the numbers.
    offerImporterFor($fixture)(['gtin' => $fixture['gtin'], 'fiyat' => '44,00', 'stok' => '9', 'liste_fiyati' => null]);

    $offer = Offer::query()->firstOrFail();

    expect($offer->price_minor)->toBe(4_400)
        ->and($offer->stock_quantity)->toBe(9)
        ->and(Offer::query()->count())->toBe(1);
});

it('writes for the uploader, because the file cannot name a seller', function (): void {
    $mine = importSeller('08690000008001');
    $theirs = importSeller('08690000008002');

    // My upload, carrying the other seller's product.
    offerImporterFor($mine)([
        'gtin' => $theirs['gtin'],
        'fiyat' => '10,00',
        'stok' => '1',
        'liste_fiyati' => null,
    ]);

    /*
     * THERE IS NO SELLER COLUMN AND THERE WILL NOT BE ONE: a spreadsheet that can
     * name an organization is a spreadsheet that can name somebody else's.
     */
    expect(Offer::query()->firstOrFail()->selling_org_id)->toBe((int) $mine['org']->getKey());
});

it('caps the chunk job, so no future defect can storm the queue', function (): void {
    $job = new ReflectionClass(OfferImportChunk::class);
    $defaults = $job->getDefaultProperties();

    /*
     * Filament's stock job has no `$tries` and no `$backoff`, and a 24-hour retry
     * window — which cost the catalogue import 29,074 attempts and ~155,000
     * duplicate failure rows overnight (ADR-075). This is the fence; the
     * translation above is the fix.
     */
    expect($defaults['tries'] ?? null)->toBe(3)
        ->and($defaults['backoff'] ?? null)->toBe([30, 120, 300])
        ->and($job->isSubclassOf(Filament\Actions\Imports\Jobs\ImportCsv::class))->toBeTrue();

    $method = new ReflectionMethod(OfferImporter::class, 'getJobRetryUntil');

    expect($method->getDeclaringClass()->getName())->toBe(OfferImporter::class);
});

it('reports a draft shop as a row failure instead of killing the chunk', function (): void {
    /*
     * **THE BLANK-FAILURE BUG, PINNED.** A seller whose shop was still a draft
     * uploaded 1,321 rows and got 3,963 failures with an EMPTY reason and no
     * completion notification at all: `noSellableStore` was thrown outside any
     * catch, so it failed the JOB rather than the row, and the queue re-ran the
     * whole chunk three times before giving up silently. A row-level answer is
     * the only kind that reaches a human.
     */
    $fixture = importSeller();

    Store::query()->update(['status' => StoreStatus::Draft]);

    $importer = offerImporterFor($fixture);

    try {
        $importer(['gtin' => $fixture['gtin'], 'fiyat' => '129,90', 'stok' => '12', 'liste_fiyati' => null]);
        $message = null;
    } catch (RowImportFailedException $exception) {
        $message = $exception->getMessage();
    }

    /*
     * The type is what keeps the chunk alive; the message is what makes the
     * failure report worth downloading. Filament stores an empty string for any
     * exception it did not expect, which is precisely what the seller saw.
     */
    expect($message)->toContain('Yayında mağazanız yok')
        ->and(Offer::query()->count())->toBe(0);
});
