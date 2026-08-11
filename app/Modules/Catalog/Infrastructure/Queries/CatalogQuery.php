<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Queries;

use App\Core\Domain\Contracts\CatalogQueryContract;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;

/**
 * Catalog's implementation of the downstream read port (ADR-040).
 *
 * Returns only scalars and booleans, never a model, so a foreign module cannot
 * reach into Catalog's internals through it — the same discipline `StoreQuery`
 * keeps. Every method is a single indexed lookup, because these run on the hot
 * path of whatever module is validating a reference.
 *
 * Soft-deleted rows are invisible here (the models' global scope), which is the
 * correct answer: a downstream module asking "does this exist" about a removed
 * product should be told no.
 *
 * @see App\Core\Domain\Contracts\CatalogQueryContract
 */
final class CatalogQuery implements CatalogQueryContract
{
    public function productExists(string $productUuid): bool
    {
        return Product::query()->where('uuid', $productUuid)->exists();
    }

    public function isProductPublished(string $productUuid): bool
    {
        return Product::query()
            ->where('uuid', $productUuid)
            ->where('status', ProductStatus::Published->value)
            ->exists();
    }

    public function variantExists(string $variantUuid): bool
    {
        return ProductVariant::query()->where('uuid', $variantUuid)->exists();
    }

    public function productUuidForVariant(string $variantUuid): ?string
    {
        $productId = ProductVariant::query()->where('uuid', $variantUuid)->value('product_id');

        if ($productId === null) {
            return null;
        }

        $uuid = Product::query()->whereKey($productId)->value('uuid');

        return is_string($uuid) ? $uuid : null;
    }

    public function categoryExists(string $categoryUuid): bool
    {
        return Category::query()->where('uuid', $categoryUuid)->active()->exists();
    }

    public function attributeExists(string $attributeUuid): bool
    {
        return Attribute::query()->where('uuid', $attributeUuid)->active()->exists();
    }

    public function proposingOrganizationUuidFor(string $productUuid): ?string
    {
        $uuid = Product::query()->where('uuid', $productUuid)->value('proposed_by_org_uuid');

        return is_string($uuid) ? $uuid : null;
    }

    /**
     * The product's KDV ratio, as a decimal string (ADR-055/056).
     *
     * TWO INDEXED LOOKUPS RATHER THAN A JOIN. A join would be one round trip, but
     * it forces qualified column names through an Eloquent builder typed to
     * `Product` — which the static analyser cannot check and a reader has to
     * decode. Both queries here are primary-key or unique-index reads against a
     * lookup table that will never hold more than a handful of rows, so the
     * second one is not the cost worth optimising; if a checkout ever needs it
     * batched, that is a contract method, not a join hidden in this one.
     *
     * `is_active` IS DELIBERATELY NOT CHECKED. A deactivated bracket is a
     * repealed one, and a product still sitting on it must still be sellable at
     * the rate it is legally classified under — refusing to answer would fail
     * checkout for goods whose classification an operator has not yet moved. The
     * inactive flag hides a bracket from the AUTHORING picker; it is not a claim
     * that products on it have no tax.
     */
    public function taxRateForProduct(string $productUuid): ?string
    {
        $taxRateId = Product::query()->where('uuid', $productUuid)->value('tax_rate_id');

        if ($taxRateId === null) {
            return null;
        }

        $rate = TaxRate::query()->whereKey($taxRateId)->value('rate');

        // Normalised to the column's scale, so pgsql's `0.2000` and sqlite's
        // `0.2` reach every caller as the same string.
        return $rate === null ? null : number_format((float) $rate, 4, '.', '');
    }

    public function publishedVariantUuidForGtin(string $gtin): ?string
    {
        $gtin = trim($gtin);

        if ($gtin === '') {
            return null;
        }

        /*
        | ONE QUERY, AND THE STATUS IS IN IT rather than checked afterwards: an
        | unpublished product must be indistinguishable from an unknown barcode
        | (@see the contract), and two steps invite a caller — or a later edit —
        | to report which one it was.
        */
        $productId = Product::query()
            ->where('gtin', $gtin)
            ->where('status', ProductStatus::Published)
            ->value('id');

        if ($productId === null) {
            return null;
        }

        /*
        | THE DEFAULT VARIANT, falling back to the first by position. v1 products
        | have exactly one (ADR-074), but the fallback costs nothing and means a
        | product whose default flag was never set still answers rather than
        | rejecting a seller's whole feed row.
        */
        return ProductVariant::query()
            ->where('product_id', $productId)
            ->orderByDesc('is_default')
            ->orderBy('position')
            ->orderBy('id')
            ->value('uuid');
    }
}
