<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Contracts\AttributeRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\ProductRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\SkuGeneratorContract;
use App\Modules\Catalog\Domain\DTOs\UpsertVariantDTO;
use App\Modules\Catalog\Domain\Events\ProductVariantCreated;
use App\Modules\Catalog\Domain\Events\ProductVariantUpdated;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;

/**
 * Creates or edits ONE SKU by hand (§2.5, ADR-039).
 *
 * The single-variant counterpart to GenerateVariantsAction, for the cases the
 * cartesian cannot express: adding one late combination, correcting a barcode,
 * re-ordering. Both write the same explicit rows.
 *
 * UNIQUENESS IS CHECKED HERE AND ENFORCED BY THE INDEX (§3.3). The check exists
 * to give a human a readable refusal; the UNIQUE(product_id, combination_key)
 * underneath is what holds when two requests race. Neither is redundant.
 *
 * A SKU IS GENERATED WHEN ABSENT, so authoring a twelve-variant product does not
 * mean inventing twelve codes by hand — and once set, an existing SKU is never
 * regenerated, because it may already be on a label.
 */
final class UpsertVariantAction extends BaseAction
{
    public function __construct(
        private readonly AttributeRepositoryContract $attributes,
        private readonly ProductRepositoryContract $products,
        private readonly SkuGeneratorContract $skus,
    ) {}

    public function handle(mixed ...$arguments): ProductVariant
    {
        /** @var Product $product */
        $product = $arguments[0];
        /** @var UpsertVariantDTO $data */
        $data = $arguments[1];

        $valueIds = $this->resolveValueIds($product, $data);
        $key = ProductVariant::combinationKeyFor($valueIds);

        $variant = $data->variantUuid === null
            ? null
            : $this->products->findVariantByUuid($data->variantUuid);

        $this->guardUniqueCombination($product, $key, $variant);

        if ($variant === null) {
            $variant = new ProductVariant;
            $variant->product_id = $product->getKey();
            $variant->position = $data->position ?? $product->variants()->count();
        } elseif ($data->position !== null) {
            $variant->position = $data->position;
        }

        $variant->combination_key = $key;
        $variant->is_default = $data->isDefault || $valueIds === [];
        $variant->barcode = $data->barcode === '' ? null : $data->barcode;

        // Generated once. A SKU already in the world is not ours to change.
        $variant->sku = $data->sku ?? $variant->sku ?? $this->skus->generate($product, $key);

        $variant->save();

        $variant->attributeValues()->sync($this->pivot($product, $valueIds));

        return $variant;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var ProductVariant $result */
        /** @var Product $product */
        $product = $arguments[0];

        if ($result->wasRecentlyCreated) {
            ProductVariantCreated::dispatch(
                $result->getKey(),
                $result->uuid,
                $product->getKey(),
                $product->uuid,
                $result->sku,
            );

            return;
        }

        ProductVariantUpdated::dispatch(
            $result->getKey(),
            $result->uuid,
            $product->getKey(),
            $result->sku,
            array_keys($result->getChanges()),
        );
    }

    /**
     * @return array<int, int>
     */
    private function resolveValueIds(Product $product, UpsertVariantDTO $data): array
    {
        if ($data->valueUuids === []) {
            return [];
        }

        $defining = $this->attributes->variantDefiningFor($product->category);

        $ids = [];

        foreach ($data->valueUuids as $valueUuid) {
            $attribute = $defining->first(
                static fn (Attribute $candidate): bool => $candidate->values->contains('uuid', $valueUuid),
            );

            // A value that belongs to no variant-defining attribute of this
            // category is not an axis here, whatever it is elsewhere (§2.3).
            if (! $attribute instanceof Attribute) {
                throw CatalogException::attributeNotInCategorySchema($valueUuid);
            }

            $ids[] = (int) $attribute->values->firstWhere('uuid', $valueUuid)->getKey();
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $valueIds
     * @return array<int, array<string, mixed>>
     */
    private function pivot(Product $product, array $valueIds): array
    {
        if ($valueIds === []) {
            return [];
        }

        $defining = $this->attributes->variantDefiningFor($product->category);
        $pivot = [];

        foreach ($valueIds as $valueId) {
            $attribute = $defining->first(
                static fn (Attribute $candidate): bool => $candidate->values->contains('id', $valueId),
            );

            $pivot[$valueId] = ['attribute_id' => $attribute?->getKey()];
        }

        return $pivot;
    }

    private function guardUniqueCombination(Product $product, string $key, ?ProductVariant $variant): void
    {
        $clash = $product->variants()
            ->where('combination_key', $key)
            ->when($variant !== null, fn ($query) => $query->whereKeyNot($variant?->getKey()))
            ->exists();

        if ($clash) {
            throw CatalogException::duplicateVariantCombination($key);
        }
    }
}
