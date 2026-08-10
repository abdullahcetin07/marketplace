<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Catalog\Application\Import\CatalogRowImporter;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Exceptions\CatalogImportException;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| C1 — one spreadsheet row becomes a published product (ADR-074)
|--------------------------------------------------------------------------
|
| **THE IMPORTER DRIVES THE AUTHORING ACTIONS AND WRITES NO MODEL ITSELF.** That
| is the ADR's central instruction, and the assertions that prove it are the ones
| about things the importer never sets: a slug in the registry, a `combination_key`
| on the variant, a `Published` status reached through submit + publish rather than
| assigned.
|
| The other half is failure behaviour: a bad row throws with a sentence a human can
| act on, and the rows around it are untouched.
|
*/

beforeEach(function (): void {
    $this->seedAll();

    /*
     * **SPATIE FETCHES THROUGH ITS OWN DOWNLOADER, NOT THE HTTP CLIENT** — the
     * default one uses `file_get_contents`, which `Http::fake()` cannot see and
     * `preventStrayRequests()` cannot block. So a test that "faked" the image
     * would quietly reach the real internet, and pass or fail on somebody else's
     * uptime. `HttpFacadeDownloader` routes it through the facade instead.
     */
    config()->set('media-library.media_downloader', \Spatie\MediaLibrary\Downloaders\HttpFacadeDownloader::class);

    Http::preventStrayRequests();
});

/**
 * A complete row, as Filament hands it over after column mapping.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @param array<string, string|null> $overrides
 *
 * @return array<string, string|null>
 */
function catalogRow(array $overrides = []): array
{
    return [
        'baslik' => 'Pamuklu Bisiklet Yaka Tişört',
        'kategori_yolu' => 'Erkek > Giyim > Tişört',
        'marka' => 'Raftabul Basics',
        'gtin' => '08691234567890',
        'aciklama' => '%100 pamuk, regular fit.',
        'kdv' => '%20',
        'gorsel_url' => null,
        ...$overrides,
    ];
}

function importingAdmin(): int
{
    /** @var Admin $admin */
    $admin = Admin::factory()->create();
    $admin->assignRole(config('marketplace.roles.super_admin'));

    return (int) $admin->getKey();
}

it('turns one row into a published product, through the real lifecycle', function (): void {
    $adminId = importingAdmin();

    $product = app(CatalogRowImporter::class)->import(catalogRow(), $adminId);

    expect($product->title_tr)->toBe('Pamuklu Bisiklet Yaka Tişört')
        ->and($product->gtin)->toBe('08691234567890')
        /*
         * **PUBLISHED BY THE ACTIONS, NOT ASSIGNED.** `SubmitProductForReviewAction`
         * is what enforces "≥1 variant and a tax bracket" and `PublishProductAction`
         * is what checks the category's required attributes. An importer that wrote
         * `status = published` would assert both without checking either.
         */
        ->and($product->status)->toBe(ProductStatus::Published)
        ->and($product->moderated_by)->toBe($adminId);

    // THE TREE WAS BUILT, three deep, and only the leaf takes products.
    $leaf = Category::query()->whereRaw('LOWER(name_tr) = ?', ['tişört'])->firstOrFail();

    expect($leaf->depth)->toBe(2)
        ->and($leaf->acceptsProducts())->toBeTrue()
        ->and($leaf->parent?->name_tr)->toBe('Giyim')
        ->and($leaf->parent?->parent?->name_tr)->toBe('Erkek')
        ->and($leaf->parent?->acceptsProducts())->toBeFalse();

    // The brand, the bracket, and the one default variant v1 gives every product.
    expect(Brand::query()->count())->toBe(1)
        ->and($product->brand?->name)->toBe('Raftabul Basics')
        ->and($product->taxRate?->code)->toBe('kdv-20')
        ->and(ProductVariant::query()->where('product_id', $product->getKey())->count())->toBe(1);

    /*
     * AND THE SLUG REGISTRY WAS WRITTEN — the thing a direct `Product::create`
     * would have skipped, and the reason the storefront could not resolve an
     * imported product at all.
     */
    expect($product->slug)->not->toBeEmpty()
        ->and(DB::table('slugs')->where('slug', $product->slug)->exists())->toBeTrue();
});

it('files two paths that end in the same word under their OWN parents', function (): void {
    $adminId = importingAdmin();
    $importer = app(CatalogRowImporter::class);

    $mens = $importer->import(catalogRow([
        'kategori_yolu' => 'Erkek > Ayakkabı',
        'gtin' => '08690000000001',
        'baslik' => 'Erkek Spor Ayakkabı',
    ]), $adminId);

    $womens = $importer->import(catalogRow([
        'kategori_yolu' => 'Kadın > Ayakkabı',
        'gtin' => '08690000000002',
        'baslik' => 'Kadın Spor Ayakkabı',
    ]), $adminId);

    /*
     * **THE BUG THE WORK ORDER'S ALGORITHM WOULD HAVE SHIPPED.** It said to walk
     * each segment with `findBySlug(Str::slug($segment))`, and `categories.slug` is
     * UNIQUE across the whole table — so the second row would have found the MEN'S
     * "Ayakkabı" and filed every women's shoe under it. Every row succeeds, nothing
     * is reported, and the tree is quietly wrong.
     *
     * A path segment only means anything relative to its parent.
     */
    expect($mens->category?->name_tr)->toBe('Ayakkabı')
        ->and($womens->category?->name_tr)->toBe('Ayakkabı')
        ->and($mens->category?->getKey())->not->toBe($womens->category?->getKey())
        ->and($mens->category?->parent?->name_tr)->toBe('Erkek')
        ->and($womens->category?->parent?->name_tr)->toBe('Kadın');

    // Two distinct categories, so two distinct slugs — the registry uniquified
    // the second, which is exactly why matching on slug was the wrong question.
    expect($mens->category?->slug)->not->toBe($womens->category?->slug);
});

it('reuses the tree instead of rebuilding it', function (): void {
    $adminId = importingAdmin();
    $importer = app(CatalogRowImporter::class);

    $importer->import(catalogRow(['gtin' => '08690000000003']), $adminId);
    $importer->import(catalogRow(['gtin' => '08690000000004', 'baslik' => 'İkinci Tişört']), $adminId);

    // Three nodes, not six — and one brand, not two.
    expect(Category::query()->count())->toBe(3)
        ->and(Brand::query()->count())->toBe(1)
        ->and(Product::query()->count())->toBe(2);
});

it('updates on a repeated GTIN rather than creating a second product', function (): void {
    $adminId = importingAdmin();
    $importer = app(CatalogRowImporter::class);

    $first = $importer->import(catalogRow(), $adminId);

    $second = $importer->import(catalogRow([
        'baslik' => 'Pamuklu Bisiklet Yaka Tişört (düzeltildi)',
    ]), $adminId);

    /*
     * **THE PROPERTY THAT MAKES A CORRECTION PASS POSSIBLE.** Fix three cells,
     * re-upload the whole sheet. Without it the second upload throws
     * `gtinAlreadyInCatalog` on every row that worked the first time, and the
     * admin's only route is deleting everything.
     */
    expect(Product::query()->count())->toBe(1)
        ->and($second->getKey())->toBe($first->getKey())
        ->and($second->title_tr)->toBe('Pamuklu Bisiklet Yaka Tişört (düzeltildi)')
        // AND IT DID NOT GROW A SECOND VARIANT.
        ->and(ProductVariant::query()->where('product_id', $first->getKey())->count())->toBe(1);
});

it('rejects a row with no title or no category path, and says which', function (): void {
    $adminId = importingAdmin();
    $importer = app(CatalogRowImporter::class);

    expect(fn () => $importer->import(catalogRow(['baslik' => '   ']), $adminId))
        ->toThrow(CatalogImportException::class, 'Zorunlu alan boş: baslik');

    expect(fn () => $importer->import(catalogRow(['kategori_yolu' => null]), $adminId))
        ->toThrow(CatalogImportException::class, 'Zorunlu alan boş: kategori_yolu');

    /*
     * NOTHING WAS LEFT BEHIND. A row that fails must not leave half a tree — the
     * category walk runs before the product is drafted, so a title checked
     * afterwards would have created "Erkek > Giyim > Tişört" for a row that never
     * became anything.
     */
    expect(Product::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0);
});

it('refuses to file a product into a category that does not accept them', function (): void {
    $adminId = importingAdmin();

    // A container somebody deliberately closed — ADR-047's moderated decision.
    $parent = Category::factory()->create(['name_tr' => 'Elektronik', 'accepts_products' => false]);
    Category::factory()->childOf($parent)->create(['name_tr' => 'Telefon', 'accepts_products' => false]);

    expect(fn () => app(CatalogRowImporter::class)->import(
        catalogRow(['kategori_yolu' => 'Elektronik > Telefon']),
        $adminId,
    ))->toThrow(CatalogImportException::class);

    /*
     * **NOT SILENTLY FLIPPED.** Setting `accepts_products` from a spreadsheet cell
     * would overturn a moderated decision about the shape of the catalogue without
     * one person choosing to. The row fails and names the category.
     */
    expect(Category::query()->whereRaw('LOWER(name_tr) = ?', ['telefon'])->first()?->acceptsProducts())
        ->toBeFalse()
        ->and(Product::query()->count())->toBe(0);
});

it('reads the KDV cell in every spelling a spreadsheet produces', function (): void {
    $adminId = importingAdmin();
    $importer = app(CatalogRowImporter::class);

    $twenty = $importer->import(catalogRow(['kdv' => '%20', 'gtin' => '08690000000010']), $adminId);
    $ten = $importer->import(catalogRow(['kdv' => '10', 'gtin' => '08690000000011']), $adminId);
    $ratio = $importer->import(catalogRow(['kdv' => '0,01', 'gtin' => '08690000000012']), $adminId);
    $blank = $importer->import(catalogRow(['kdv' => null, 'gtin' => '08690000000013']), $adminId);

    expect($twenty->taxRate?->code)->toBe('kdv-20')
        ->and($ten->taxRate?->code)->toBe('kdv-10')
        // "0,01" — a Turkish decimal comma, which Excel writes by default.
        ->and($ratio->taxRate?->code)->toBe('kdv-1')
        /*
         * AN UNREADABLE CELL FALLS BACK RATHER THAN FAILING THE ROW. Getting a
         * product in at the standard bracket and correcting it later beats
         * rejecting a catalogue over a formatting choice — and the bracket is
         * visible and editable on the product afterwards.
         */
        ->and($blank->taxRate?->code)->toBe('kdv-20');

    expect(TaxRate::query()->count())->toBeGreaterThan(0);
});

it('fetches the images it is given and shrugs off the ones it cannot', function (): void {
    $adminId = importingAdmin();

    // A REAL ONE-PIXEL PNG, inline: spatie sniffs the body, so a string of "x"
    // is rejected as a non-image and the test would pass for the wrong reason.
    $pixel = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ) ?: '';

    Http::fake([
        'cdn.example.com/tisort-1.jpg' => Http::response($pixel, 200, ['Content-Type' => 'image/png']),
        // The second one is a dead link, which must not cost the row its product.
        'cdn.example.com/yok.png' => Http::response('not found', 404),
    ]);

    $product = app(CatalogRowImporter::class)->import(catalogRow([
        'gorsel_url' => 'https://cdn.example.com/tisort-1.jpg | https://cdn.example.com/yok.png | https://cdn.example.com/animasyon.gif',
    ]), $adminId);

    /*
     * ONE IMAGE ATTACHED, ONE 404 SWALLOWED, ONE GIF NEVER FETCHED. A dead link
     * or an animated gif is not a reason to reject a product whose every other
     * cell is right — and the product being PUBLISHED is the assertion that
     * matters here.
     */
    expect($product->status)->toBe(ProductStatus::Published)
        ->and($product->getMedia('images')->count())->toBe(1);

    // The gif was judged by extension and never requested at all.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'animasyon.gif'));
});
