<?php

declare(strict_types=1);

use App\Modules\Catalog\Application\Actions\ArchiveProductAction;
use App\Modules\Catalog\Application\Actions\PublishProductAction;
use App\Modules\Catalog\Application\Listeners\SyncProductSearchIndex;
use App\Modules\Catalog\Domain\DTOs\ModerationDecisionDTO;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Events\ProductArchived;
use App\Modules\Catalog\Domain\Events\ProductPublished;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Catalog — search indexing (§10)
|--------------------------------------------------------------------------
|
| `SCOUT_DRIVER=null` in the suite (phpunit.xml), so these do not reach a
| cluster. What they pin is everything that is OURS: which products are
| indexable, what the document contains, and that the two lifecycle events are
| the ones wired.
|
| The document shape matters more than it looks. It is a public read surface —
| a client reads these fields — so a leaked internal id here is the same defect
| as one in an API response.
|
*/

/**
 * A published product with a brand, a descriptive attribute and two variants.
 */
function indexableProduct(): Product
{
    $root = Category::factory()->create(['name_tr' => 'Giyim']);
    $category = Category::factory()->childOf($root)->create(['name_tr' => 'Tişört']);
    $brand = Brand::factory()->create(['name' => 'Beko']);

    $material = Attribute::factory()->withValues(2)->create(['code' => 'malzeme']);
    $colour = Attribute::factory()->variantDefining()->withValues(2)->create(['code' => 'renk']);

    $product = Product::factory()->for($category, 'category')->for($brand)->published()->create([
        'title_tr' => 'Pamuklu Tişört',
        'title_en' => 'Cotton T-Shirt',
        'gtin' => '8691234567890',
    ]);

    $cotton = $material->values->first();
    $product->descriptiveAttributes()->attach($material, ['attribute_value_id' => $cotton->getKey()]);

    foreach ($colour->values as $index => $value) {
        $variant = ProductVariant::factory()->for($product)->create([
            'sku' => 'TSHIRT-'.$index,
            'combination_key' => ProductVariant::combinationKeyFor([$value->getKey()]),
        ]);
        $variant->attributeValues()->attach($value, ['attribute_id' => $colour->getKey()]);
    }

    return $product->fresh()->load($product->searchRelations());
}

it('indexes only published products', function (): void {
    // §10 — the backstop under the listeners: no other save can sneak a draft
    // into the index.
    $category = Category::factory()->create();

    $draft = Product::factory()->for($category, 'category')->create();
    $pending = Product::factory()->for($category, 'category')->pendingReview()->create();
    $published = Product::factory()->for($category, 'category')->published()->create();
    $rejected = Product::factory()->for($category, 'category')->rejected()->create();
    $archived = Product::factory()->for($category, 'category')->archived()->create();

    expect($draft->shouldBeSearchable())->toBeFalse()
        ->and($pending->shouldBeSearchable())->toBeFalse()
        ->and($published->shouldBeSearchable())->toBeTrue()
        ->and($rejected->shouldBeSearchable())->toBeFalse()
        ->and($archived->shouldBeSearchable())->toBeFalse();
});

it('builds a document with the public identifier, never the internal id', function (): void {
    // The index is read by clients (non-negotiable #7). A leaked internal id
    // here is the same defect as one in an API response.
    $product = indexableProduct();

    $document = $product->toSearchableArray();

    expect($document['id'])->toBe($product->uuid)
        ->and($document['id'])->not->toBe((string) $product->getKey())
        ->and($document)->not->toHaveKey('category_id')
        ->and($document)->not->toHaveKey('brand_id')
        ->and($document)->not->toHaveKey('proposed_by_org_id');
});

it('carries no price and no stock into the index', function (): void {
    // ADR-037. When Offer ships, price-sorted search reads Offer's index.
    $document = indexableProduct()->toSearchableArray();

    foreach (['price', 'stock', 'quantity', 'currency'] as $forbidden) {
        expect($document)->not->toHaveKey($forbidden);
    }
});

it('indexes the taxonomy, the brand and every SKU', function (): void {
    $product = indexableProduct();

    $document = $product->toSearchableArray();

    expect($document['title'])->toBe('Pamuklu Tişört')
        ->and($document['brand'])->toBe('Beko')
        ->and($document['category'])->toBe('Tişört')
        // The path makes "everything under Giyim" a prefix filter on the index,
        // the same shape the database uses (§13.1).
        ->and($document['category_path'])->toBe($product->category->path)
        ->and($document['gtin'])->toBe('8691234567890')
        ->and($document['status'])->toBe(ProductStatus::Published->value)
        ->and($document['skus'])->toHaveCount(2)
        ->and($document['skus'])->toContain('TSHIRT-0', 'TSHIRT-1');
});

it('flattens attributes into code:value facets, from the product and its variants', function (): void {
    // The shape a facet filter queries — `attributes: "renk:kirmizi"` — ready
    // for when Offer ships (§10).
    $document = indexableProduct()->toSearchableArray();

    /** @var array<int, string> $facets */
    $facets = $document['attributes'];

    $material = array_filter($facets, static fn (string $f): bool => str_starts_with($f, 'malzeme:'));
    $colour = array_filter($facets, static fn (string $f): bool => str_starts_with($f, 'renk:'));

    expect($facets)->toBeArray()
        // One descriptive attribute from the product...
        ->and($material)->toHaveCount(1)
        // ...and one axis value per variant.
        ->and($colour)->toHaveCount(2);
});

it('maps Turkish text through the Turkish analyser', function (): void {
    // Not polish: without it `İSTANBUL` does not match `istanbul` and `ürün`
    // does not match `urun` (docs/search.md). Keyword fields stay unanalysed
    // because a status or a SKU is matched exactly or not at all.
    $mapping = indexableProduct()->searchableMapping();

    expect($mapping['title']['analyzer'])->toBe('turkish_analyzer')
        ->and($mapping['description']['analyzer'])->toBe('turkish_analyzer')
        ->and($mapping['status']['type'])->toBe('keyword')
        ->and($mapping['skus']['type'])->toBe('keyword')
        ->and($mapping['id']['type'])->toBe('keyword');
});

it('boosts the title above everything else', function (): void {
    // A shopper typing "tişört" means the product, not a description that
    // mentions one.
    $fields = indexableProduct()->searchableFields();

    expect($fields[0])->toBe('title^5')
        ->and($fields)->toContain('brand^3', 'category^2', 'description');
});

it('subscribes to publication and archival, and to nothing else', function (): void {
    // §10 — exactly the two transitions that change whether a product is
    // findable. Observing every save would put a rejected product through the
    // indexing pipeline for nothing.
    $subscriptions = app(SyncProductSearchIndex::class)->subscribe(app('events'));

    expect(array_keys($subscriptions))->toBe([
        ProductPublished::class,
        ProductArchived::class,
    ]);
});

it('reacts to the real lifecycle events', function (): void {
    // End to end with the null driver: what is proven is that publishing and
    // archiving reach the listener at all, which is the wiring most likely to
    // be silently missing.
    Event::fake([ProductPublished::class, ProductArchived::class]);

    $category = Category::factory()->create();
    $product = Product::factory()->for($category, 'category')->pendingReview()->create();
    ProductVariant::factory()->for($product)->default()->create();

    PublishProductAction::make()->run($product->fresh(), new ModerationDecisionDTO);
    Event::assertDispatched(ProductPublished::class);

    ArchiveProductAction::make()->run($product->fresh());
    Event::assertDispatched(ProductArchived::class);
});
