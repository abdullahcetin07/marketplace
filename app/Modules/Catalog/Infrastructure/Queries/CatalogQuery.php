<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Queries;

use App\Core\Domain\Contracts\CatalogQueryContract;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;

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
}
