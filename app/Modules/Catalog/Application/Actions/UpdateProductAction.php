<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Contracts\BrandRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\CategoryRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\CategorySlugGeneratorContract;
use App\Modules\Catalog\Domain\Contracts\ProductRepositoryContract;
use App\Modules\Catalog\Domain\DTOs\UpdateProductDTO;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\TaxRate;

/**
 * Edits a product's own fields.
 *
 * PATCH semantics via the DTO's `present` list, for a specific reason: `gtin` is
 * the shared catalog's dedup key, and an edit form that omitted the field and
 * therefore cleared it would quietly un-deduplicate a product.
 *
 * NO STATUS FIELD. Moderation state moves only through the lifecycle actions
 * (§3.1) — allowing it here would let a content edit publish a product.
 *
 * CHANGING THE CATEGORY re-checks leaf-ness, because the schema a product must
 * satisfy comes from its category and a container has none.
 */
final class UpdateProductAction extends BaseAction
{
    public function __construct(
        private readonly CategoryRepositoryContract $categories,
        private readonly BrandRepositoryContract $brands,
        private readonly ProductRepositoryContract $products,
        private readonly CategorySlugGeneratorContract $slugs,
    ) {}

    public function handle(mixed ...$arguments): Product
    {
        /** @var Product $product */
        $product = $arguments[0];
        /** @var UpdateProductDTO $data */
        $data = $arguments[1];

        if ($data->has('categoryUuid') && $data->categoryUuid !== null) {
            $category = $this->categories->findOrFailByUuid($data->categoryUuid);

            // ADR-047 — re-checked on a category change, not only at draft:
            // moving a product into a container is the same mistake as
            // drafting it there.
            if (! $category->acceptsProducts()) {
                throw CatalogException::categoryDoesNotAcceptProducts($category->uuid);
            }

            $product->category_id = $category->getKey();
        }

        if ($data->has('brandUuid')) {
            $product->brand_id = $data->brandUuid === null
                ? null
                : (int) $this->brands->findOrFailByUuid($data->brandUuid)->getKey();
        }

        /*
        | The KDV bracket (ADR-056), under the same PATCH semantics as the brand: a
        | form that does not render the field leaves the classification alone
        | rather than clearing it.
        |
        | An unknown uuid is ignored rather than raising: the picker only offers
        | real brackets, so this can only be reached by a tampered payload, and the
        | submission check refuses a product with no bracket anyway.
        */
        if ($data->has('taxRateUuid') && $data->taxRateUuid !== null) {
            $taxRateId = TaxRate::query()->where('uuid', $data->taxRateUuid)->value('id');

            if ($taxRateId !== null) {
                $product->tax_rate_id = (int) $taxRateId;
            }
        }

        if ($data->has('gtin')) {
            $product->gtin = $this->resolveGtin($product, $data->gtin);
        }

        if ($data->title !== []) {
            $product->fillLocalized('title', $data->title);
        }

        if ($data->description !== []) {
            $product->fillLocalized('description', $data->description);
        }

        if ($data->has('slug') && $data->slug !== null && $data->slug !== $product->slug) {
            $product->slug = $this->slugs->forProduct($data->slug, (int) $product->getKey());
        }

        $product->save();

        return $product;
    }

    /**
     * A GTIN already held by a DIFFERENT product is the dedup collision (§3.4);
     * the same product keeping its own is not.
     */
    private function resolveGtin(Product $product, ?string $gtin): ?string
    {
        $gtin = $gtin === null ? null : trim($gtin);

        if ($gtin === null || $gtin === '') {
            return null;
        }

        $existing = $this->products->findByGtin($gtin);

        if ($existing !== null && $existing->getKey() !== $product->getKey()) {
            throw CatalogException::gtinAlreadyInCatalog($gtin, $existing->uuid);
        }

        return $gtin;
    }
}
