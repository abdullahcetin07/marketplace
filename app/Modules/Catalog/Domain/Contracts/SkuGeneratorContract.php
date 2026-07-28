<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contracts;

use App\Modules\Catalog\Domain\Models\Product;

/**
 * Produces a globally-unique SKU for a variant (§3.3).
 *
 * A CONTRACT for the same reason Store's slug and number generators are: the
 * aggregate must not encode the scheme. Today's is a readable prefix plus a
 * uniqueness suffix; a future one may encode category, brand or a checksum, and
 * that should be a binding swap rather than a change to every action that
 * creates a variant.
 *
 * @see App\Modules\Catalog\Infrastructure\Generators\DefaultSkuGenerator
 */
interface SkuGeneratorContract
{
    /**
     * A globally-unique SKU for a variant of this product.
     *
     * `combinationKey` is passed so a scheme that wants to encode the axes can,
     * without the caller having to know whether this one does.
     */
    public function generate(Product $product, string $combinationKey): string;
}
