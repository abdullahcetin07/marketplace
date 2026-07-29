<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Core\Domain\Contracts\CatalogQueryContract;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The boundary Offer exists to keep: no price, no stock in the Catalog
|--------------------------------------------------------------------------
|
| "A Product has no price and no stock" (ADR-037) is the platform's defining
| decision — it is what lets one catalog entry be sold by many sellers, and it is
| the reason the Catalog was built shared rather than per-seller. Catalog already
| asserts it for its own Phase 1 (Catalog §15.2). This file asserts it STILL
| HOLDS now that a module with prices exists and reaches into the catalog every
| day.
|
| The pressure is real and directional: the cheap way to render a seller's offer
| list, or to sort search by price, is always to put a price column next to the
| product. Each assertion below is one place that shortcut would first appear.
|
| The other half of the boundary — that Offer imports no module — is an
| architecture rule, asserted in tests/Architecture/LayeringTest.php where it
| fails the build without needing a database.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * Column names that would mean the catalog had started holding commerce.
 *
 * Substrings rather than exact names on purpose: `price`, `unit_price` and
 * `base_price_minor` are the same mistake, and enumerating exact names would
 * only catch the one somebody happened to think of.
 *
 * @return array<int, string>
 */
function commerceColumnFragments(): array
{
    return ['price', 'stock', 'quantity', 'commission', 'discount', 'currency', 'vat', 'tax'];
}

it('keeps every commerce concern out of the catalog schema', function (string $table): void {
    $offending = [];

    foreach (Schema::getColumnListing($table) as $column) {
        foreach (commerceColumnFragments() as $fragment) {
            if (str_contains($column, $fragment)) {
                $offending[] = $column;
            }
        }
    }

    expect($offending)->toBe(
        [],
        "{$table} has grown a commerce column: ".implode(', ', $offending)
    );
})->with(['products', 'product_variants', 'categories', 'brands']);

it('keeps price and stock out of the catalog search document', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create();
    ProductVariant::factory()->for($product)->create();

    $document = $product->load($product->searchRelations())->toSearchableArray();

    // Sorting search by price is the single most tempting reason to smuggle a
    // price into this document. It belongs in Offer's index (§10), which is
    // joined on `product_uuid`.
    foreach (['price', 'price_minor', 'stock', 'stock_quantity', 'in_stock'] as $field) {
        expect($document)->not->toHaveKey($field);
    }
});

it('offers no way to ask the catalog what something costs', function (): void {
    $methods = array_merge(
        get_class_methods(CatalogQueryContract::class),
        get_class_methods(CatalogBrowseContract::class),
    );

    // The contracts are the only doors into the Catalog from outside it. A
    // `priceFor()` here would be the boundary failing at its narrowest point —
    // and it would be answered by copying Offer's data back into Catalog.
    foreach ($methods as $method) {
        foreach (['price', 'stock', 'commission'] as $fragment) {
            expect(str_contains(mb_strtolower($method), $fragment))->toBeFalse(
                "CatalogQueryContract/CatalogBrowseContract exposes {$method}"
            );
        }
    }
});

it('returns no price or stock from the catalog browse port', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create();
    ProductVariant::factory()->for($product)->create();

    $browse = app(CatalogBrowseContract::class);

    $item = $browse->searchPublishedProducts()['items'][0];
    $variant = $browse->variantsForProduct($product->uuid)[0];
    $summary = $browse->productSummaries([$product->uuid])[$product->uuid];

    // The port Offer uses every day is where a price would be most convenient
    // and most wrong: it would make the catalog the source of truth for a
    // number only a seller may set.
    foreach ([$item, $variant, $summary] as $payload) {
        foreach (commerceColumnFragments() as $fragment) {
            foreach (array_keys($payload) as $key) {
                expect(str_contains($key, $fragment))->toBeFalse(
                    "catalog browse payload exposes {$key}"
                );
            }
        }
    }
});

it('keeps the catalog free of any offers relation', function (): void {
    // The reverse direction of the same boundary. `$product->offers` would make
    // Catalog depend on Offer — the import LayeringTest forbids — and would
    // quietly become the way every future feature reads prices.
    foreach ([Product::class, ProductVariant::class] as $model) {
        foreach (get_class_methods($model) as $method) {
            foreach (['offer', 'price', 'stock', 'inventory'] as $fragment) {
                expect(str_contains(mb_strtolower($method), $fragment))->toBeFalse(
                    "{$model}::{$method}() reaches into commerce"
                );
            }
        }
    }
});
