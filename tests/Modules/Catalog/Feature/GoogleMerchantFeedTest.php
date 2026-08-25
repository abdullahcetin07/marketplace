<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Google Merchant Center product feed (BUILD_GOOGLE_MERCHANT_FEED.md)
|--------------------------------------------------------------------------
|
| The nightly RSS file Google fetches. The rules worth testing hardest are the
| ones where being WRONG costs the Merchant Center account rather than a page
| render: a submitted item with no description or no image is a disapproval, and
| a disapproval counts against the whole account. So the build drops those itself
| and reports how many — and the report is what tells the owner how much Turkish
| copy is still missing.
|
| The price assertions are the other half. It is the buy box price, KDV-INCLUSIVE
| (ADR-055/061), rendered as a decimal string from minor units (ADR-005). A float
| anywhere on that path is a rounding bug in a shopping ad.
|
*/

beforeEach(function (): void {
    $this->seedAll();

    Storage::fake('public');
    Storage::fake(config('marketplace.media.public_disk'));

    config()->set('feed.google.storefront_url', 'https://raftabul.com');
    config()->set('feed.google.access_token', '');
    config()->set('feed.google.excluded_category_slugs', []);
    config()->set('feed.google.min_description_length', 30);
});

/**
 * A published, sellable product with everything the feed demands.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @param array<string, mixed> $overrides
 */
function feedableProduct(
    string $title,
    int $priceMinor = 12_990,
    array $overrides = [],
    bool $withImage = true,
    int $stock = 5,
    ?Category $category = null,
): Product {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category ??= Category::factory()->childOf(Category::factory()->create())->create();

    $product = Product::factory()->for($category, 'category')->published()->create(array_merge([
        'title_tr' => $title,
        'title_en' => $title,
        'description_tr' => 'Bu ürün için yeterince uzun, gerçekçi bir Türkçe açıklama metni.',
        'description_en' => 'A description comfortably past the minimum length for this feed.',
        'brand_id' => Brand::factory()->create(['name' => 'Marka'])->getKey(),
    ], $overrides));

    // Barcodes are UNIQUE catalogue-wide (the GTIN guard, ADR-037), so a fixture
    // that hard-codes one can only ever build a single product.
    static $barcode = 8_690_000_000_000;
    $barcode++;

    // Explicitly the DEFAULT: the factory ships `is_default => false`, and the
    // feed row stands for the default variant.
    $variant = ProductVariant::factory()->for($product)->create([
        'barcode' => (string) $barcode,
        'is_default' => true,
    ]);

    if ($withImage) {
        $product->addMedia(UploadedFile::fake()->image($title.'.png', 800, 800))
            ->toMediaCollection('images');
    }

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: $priceMinor,
        stockQuantity: $stock,
    ));

    return $product->refresh();
}

function builtFeed(): string
{
    Artisan::call('feed:build-google-merchant');

    return Storage::disk('public')->get('feeds/google-merchant.xml');
}

/**
 * The one `<item>` block belonging to a product.
 *
 * A feed is one string holding every product, so `expect($xml)->toContain(...)`
 * answers "somebody's item says this", which is not the question when the point
 * is that THIS item does.
 */
function itemFor(string $xml, Product $product): string
{
    foreach (explode('<item>', $xml) as $item) {
        if (str_contains($item, '/'.$product->slug.'</link>')) {
            return $item;
        }
    }

    throw new RuntimeException("No feed item for product {$product->slug}.");
}

it('writes one item per sellable product and reports what it dropped', function (): void {
    // TWO ROWS MINIMUM, always. Laravel arms the lazy-loading guard in
    // `Builder::hydrate()` behind `count($items) > 1`, so a single-product
    // fixture renders an unloaded relation happily and proves nothing.
    feedableProduct('Nemlendirici');
    feedableProduct('Güneş Kremi');
    feedableProduct('Açıklamasız', overrides: ['description_tr' => '', 'description_en' => '']);
    feedableProduct('Görselsiz', withImage: false);

    $xml = builtFeed();

    expect(substr_count($xml, '<item>'))->toBe(2)
        ->and($xml)->toContain('Nemlendirici')
        ->and($xml)->toContain('Güneş Kremi')
        ->and($xml)->not->toContain('Açıklamasız')
        ->and($xml)->not->toContain('Görselsiz');

    $report = app(App\Modules\Catalog\Application\Services\GoogleMerchantFeed::class)->build();

    expect($report['written'])->toBe(2)
        ->and($report['dropped_no_description'])->toBe(1)
        ->and($report['dropped_no_image'])->toBe(1);
});

it('is well-formed XML in the g: namespace with every required field', function (): void {
    feedableProduct('Serum');
    feedableProduct('Tonik');

    $xml = builtFeed();

    $document = new DOMDocument;

    expect($document->loadXML($xml))->toBeTrue();

    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('g', 'http://base.google.com/ns/1.0');

    $items = $xpath->query('//item');

    expect($items)->not->toBeFalse()
        ->and($items->length)->toBe(2);

    foreach (['g:id', 'title', 'description', 'link', 'g:image_link', 'g:price', 'g:availability', 'g:brand', 'g:condition'] as $field) {
        expect($xpath->query('//item/'.$field)->length)->toBe(2, $field.' is missing');
    }
});

it('prices at the KDV-inclusive buy box, as a decimal string', function (): void {
    feedableProduct('Krem', priceMinor: 12_990);
    feedableProduct('Losyon', priceMinor: 7_500);

    $xml = builtFeed();

    // The gross price a shopper is charged, not a net one — and there is NO
    // separate tax node, or Google would add VAT on top of a VAT-inclusive price.
    expect($xml)->toContain('<g:price>129.90 TRY</g:price>')
        ->and($xml)->toContain('<g:price>75.00 TRY</g:price>')
        ->and($xml)->not->toContain('129.9<')
        ->and($xml)->not->toMatch('/<tax>/');
});

it('links to the storefront by flat slug and gives an absolute image url', function (): void {
    $product = feedableProduct('Maske');
    feedableProduct('Peeling');

    $xml = builtFeed();

    expect($xml)->toContain('<link>https://raftabul.com/'.$product->slug.'</link>')
        ->and($xml)->toMatch('#<g:image_link>https?://#');
});

it('writes the gtin and says so through identifier_exists', function (): void {
    $withGtin = feedableProduct('Barkodlu');
    $without = feedableProduct('Barkodsuz');
    $without->variants()->update(['barcode' => null]);
    $without->update(['gtin' => null]);

    $xml = builtFeed();

    $gtin = (string) $withGtin->variants()->first()->barcode;

    expect($xml)->toContain('<g:gtin>'.$gtin.'</g:gtin>')
        ->and($xml)->toContain('<g:identifier_exists>yes</g:identifier_exists>')
        ->and($xml)->toContain('<g:identifier_exists>no</g:identifier_exists>');
});

it('claims an identifier for a GTIN with no brand behind it', function (): void {
    /*
    | The 2026-08-24 regression, measured on the live feed: every one of 6,933
    | items had a GTIN and 3,329 of them said `identifier_exists: no`, because
    | the flag was answering for the BRAND. A GTIN is a unique identifier on its
    | own — brand+MPN is the alternative to it, not a second half of it — and
    | each `no` told Google to disregard the barcode, which on a new domain is
    | the strongest matching signal there is.
    |
    | Two products so the assertion cannot pass on the other one's tag.
    */
    $brandless = feedableProduct('Marks1z', overrides: ['brand_id' => null]);
    feedableProduct('Markal1');

    $xml = builtFeed();

    $item = itemFor($xml, $brandless);

    expect($item)->not->toContain('<g:brand>')
        ->and($item)->toContain('<g:gtin>')
        ->and($item)->toContain('<g:identifier_exists>yes</g:identifier_exists>');
});

it('never leaks an internal integer id', function (): void {
    $first = feedableProduct('Birinci');
    $second = feedableProduct('İkinci');

    $xml = builtFeed();

    // The public identifier is the variant uuid (ADR-005 §7). An integer id here
    // would publish the catalogue's row count to a competitor's crawler.
    expect($xml)->toContain('<g:id>'.$first->variants()->first()->uuid.'</g:id>')
        ->and($xml)->not->toContain('<g:id>'.$first->getKey().'</g:id>')
        ->and($xml)->not->toContain('<g:id>'.$second->getKey().'</g:id>');
});

it('keeps an excluded category and its descendants out', function (): void {
    $parent = Category::factory()->create(['slug' => 'takviye-gida']);
    $child = Category::factory()->childOf($parent)->create();

    feedableProduct('Vitamin', category: $child);
    feedableProduct('Serbest');

    config()->set('feed.google.excluded_category_slugs', ['takviye-gida']);

    $xml = builtFeed();

    // Excluding a branch excludes its leaves: a policy strike lands on
    // "supplements", not on the leaf somebody happened to file a product under.
    expect($xml)->toContain('Serbest')
        ->and($xml)->not->toContain('Vitamin');
});

it('ships with the supplement branch excluded, and with the strays that escaped it', function (): void {
    /*
    | The exclusion list is a POLICY decision (owner, 2026-08-24), not a local
    | setting, so the shipped default is asserted rather than left to whatever
    | an environment happens to define.
    |
    | The three doubled slugs are the part worth pinning. They are supplement
    | categories sitting at the ROOT of the catalogue instead of under
    | `besin-takviyeleri` — an import artefact — so excluding the parent branch
    | alone left D3-K2 (79 products) and magnesium (44) in the feed. If the
    | catalogue tree is ever repaired, these entries become harmless no-ops and
    | this test is the note explaining why they were there.
    */
    $config = require base_path('config/feed.php');

    $slugs = $config['google']['excluded_category_slugs'];

    expect($slugs)
        ->toContain('besin-takviyeleri')
        ->toContain('d3-k2-vitaminid3-k2-vitamini')
        ->toContain('magnezyum-bisglisinatmagnezyum-bisglisinat')
        ->toContain('zayiflama-ve-diyet-urunleri')
        ->toContain('outlet-besin-takviyeleri')
        ->toContain('sac-bakim-vitamin-takviyeleri')
        // The PARENTS are not excluded, and that is the whole shape of this
        // list: supplements go, the aisles they were filed under stay. Naming
        // `saglik-ve-medikal`, `outlet-urunler` or `sac-bakimi` here would drop
        // hundreds of items Google is happy to take.
        ->and($slugs)->not->toContain('saglik-ve-medikal')
        ->and($slugs)->not->toContain('outlet-urunler')
        ->and($slugs)->not->toContain('sac-bakimi');
});

it('serves the built file as xml, and 404s before it exists', function (): void {
    $this->get('/feed/google-merchant.xml')->assertNotFound();

    feedableProduct('Şampuan');
    feedableProduct('Saç Kremi');
    Artisan::call('feed:build-google-merchant');

    $this->get('/feed/google-merchant.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=utf-8');
});

it('demands the token only when one is configured', function (): void {
    feedableProduct('Sabun');
    feedableProduct('Duş Jeli');
    Artisan::call('feed:build-google-merchant');

    config()->set('feed.google.access_token', 'gizli');

    // 404 rather than 403: a 403 confirms the URL is right and invites guessing.
    $this->get('/feed/google-merchant.xml')->assertNotFound();
    $this->get('/feed/google-merchant.xml?key=yanlis')->assertNotFound();
    $this->get('/feed/google-merchant.xml?key=gizli')->assertOk();
});

it('escapes text rather than emitting broken xml', function (): void {
    feedableProduct('Ampul & Serum <b>güçlü</b>');
    feedableProduct('Normal');

    $xml = builtFeed();

    $document = new DOMDocument;

    // The title carries an ampersand and the description arrives as markup. Both
    // are ways to produce a file Google rejects in full rather than in part.
    expect($document->loadXML($xml))->toBeTrue()
        ->and($xml)->toContain('&amp;')
        ->and($xml)->not->toContain('<b>');
});

it('marks a sold-out variant out_of_stock rather than dropping it', function (): void {
    /*
    | THE DRIFT CASE. The buy box is answered per PRODUCT and availability per
    | VARIANT, so a product whose default variant is sold out while a sibling is
    | not still has a price — and the feed row stands for the default variant.
    | Saying `in_stock` there sends a shopper to a page that cannot sell them the
    | thing they clicked, which Google penalises harder than it penalises
    | `out_of_stock`.
    */
    $product = feedableProduct('İki Varyantlı');
    feedableProduct('Tek Varyantlı');

    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $second = ProductVariant::factory()->for($product)->create([
        'barcode' => '8699999999991',
        'is_default' => false,
    ]);

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $second->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: 9_900,
        stockQuantity: 4,
    ));

    // Empty the default variant's pool, leaving the product sellable through the
    // sibling.
    $default = $product->variants()->where('is_default', true)->firstOrFail();

    app(App\Modules\Offer\Application\Actions\UpdateOfferStockAction::class)->run(
        App\Modules\Offer\Domain\Models\Offer::query()->where('variant_uuid', $default->uuid)->firstOrFail(),
        new App\Modules\Offer\Domain\DTOs\UpdateOfferStockDTO(stockQuantity: 0),
    );

    $xml = builtFeed();

    expect($xml)->toContain('İki Varyantlı')
        ->and($xml)->toContain('<g:availability>out_of_stock</g:availability>')
        ->and($xml)->toContain('<g:availability>in_stock</g:availability>');
});

it('refuses to replace a good feed with an empty one', function (): void {
    feedableProduct('Kalıcı');
    feedableProduct('Bir Diğeri');

    $good = builtFeed();

    expect(substr_count($good, '<item>'))->toBe(2);

    /*
    | An empty feed is not a smaller feed. It is well-formed XML that says this
    | merchant sells nothing, and Google reads it exactly that way — the whole
    | catalogue goes from listed to withdrawn, and coming back is a re-review.
    | The ways to produce one are ordinary (a stale `is_sellable`, an Offer
    | outage) and all of them are temporary, while the withdrawal is not.
    */
    Product::query()->update(['is_sellable' => false]);

    $exit = Artisan::call('feed:build-google-merchant');

    expect($exit)->toBe(1)
        ->and(Storage::disk('public')->get('feeds/google-merchant.xml'))->toBe($good);
});

it('leaves no half-written file behind when a build produces nothing', function (): void {
    feedableProduct('Açıklamasız Tek', overrides: ['description_tr' => '', 'description_en' => '']);

    Artisan::call('feed:build-google-merchant');

    expect(Storage::disk('public')->exists('feeds/google-merchant.xml'))->toBeFalse()
        ->and(Storage::disk('public')->exists('feeds/google-merchant.xml.building'))->toBeFalse();

    // And the route says so, rather than serving an empty channel.
    $this->get('/feed/google-merchant.xml')->assertNotFound();
});
