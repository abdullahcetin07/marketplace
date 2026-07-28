<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Contracts\AttributeRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\SkuGeneratorContract;
use App\Modules\Catalog\Domain\DTOs\GenerateVariantsDTO;
use App\Modules\Catalog\Domain\Events\ProductVariantCreated;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;

/**
 * Multiplies the seller's chosen values out into SKUs (§13.4, ruled).
 *
 * CARTESIAN AUTO-GENERATE, PRUNABLE. The seller picks Renk ∈ {Kırmızı, Mavi} and
 * Beden ∈ {M, L}; four variants appear; they delete the two they do not stock.
 * The domain stores explicit variants either way — this action is a convenience
 * over `UpsertVariantAction`, not a different storage model, which is what keeps
 * "generate" and "add one by hand" from diverging.
 *
 * THE CAP IS NOT DEFENSIVE PROGRAMMING. Cartesian growth is multiplicative:
 * five axes of five values is 3,125 rows from one form submission, and a seller
 * who ticks "select all" on four attributes will do it by accident. The action
 * refuses above `config('catalog.variants.max_generated')` rather than letting a
 * plausible-looking selection write a table's worth of SKUs.
 *
 * AN EMPTY SELECTION IS VALID and produces the single `is_default` variant of a
 * product with no axes (ADR-039 — never a special case).
 *
 * IDEMPOTENT ON RE-RUN: combinations that already exist are left alone, so
 * adding a colour to an existing product generates only the new rows and never
 * disturbs the SKUs a seller has already printed on labels.
 */
final class GenerateVariantsAction extends BaseAction
{
    public function __construct(
        private readonly AttributeRepositoryContract $attributes,
        private readonly SkuGeneratorContract $skus,
    ) {}

    /**
     * @return array<int, ProductVariant>
     */
    public function handle(mixed ...$arguments): array
    {
        /** @var Product $product */
        $product = $arguments[0];
        /** @var GenerateVariantsDTO $data */
        $data = $arguments[1];

        $axes = $this->resolveAxes($product, $data);
        $combinations = $this->cartesian($axes);

        $max = (int) config('catalog.variants.max_generated', 200);

        if (count($combinations) > $max) {
            throw CatalogException::tooManyVariants(count($combinations), $max);
        }

        $excluded = array_flip($data->exclude);
        $existing = $product->variants()->pluck('combination_key')->all();
        $created = [];

        foreach ($combinations as $valueIds) {
            $key = ProductVariant::combinationKeyFor($valueIds);

            if (isset($excluded[$key]) || in_array($key, $existing, true)) {
                continue;
            }

            $created[] = $this->createVariant($product, $valueIds, $key, count($existing) + count($created));
        }

        if ($data->pruneMissing) {
            $this->prune($product, $combinations, $excluded);
        }

        return $created;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var array<int, ProductVariant> $result */
        /** @var Product $product */
        $product = $arguments[0];

        foreach ($result as $variant) {
            ProductVariantCreated::dispatch(
                $variant->getKey(),
                $variant->uuid,
                $product->getKey(),
                $product->uuid,
                $variant->sku,
            );
        }
    }

    /**
     * The chosen values per axis, as internal ids, validated against the
     * category's schema.
     *
     * An axis the category does not mark variant-defining is refused rather than
     * ignored: quietly dropping it would generate a variant set that does not
     * match what the seller ticked, and they would not find out until a buyer
     * could not pick a size.
     *
     * @return array<int, array<int, int>>
     */
    private function resolveAxes(Product $product, GenerateVariantsDTO $data): array
    {
        if ($data->selection === []) {
            return [];
        }

        $defining = $this->attributes->variantDefiningFor($product->category)->keyBy('uuid');

        $axes = [];

        foreach ($data->selection as $attributeUuid => $valueUuids) {
            $attribute = $defining->get($attributeUuid);

            if (! $attribute instanceof Attribute) {
                throw CatalogException::attributeNotInCategorySchema($attributeUuid);
            }

            $ids = $attribute->values
                ->whereIn('uuid', $valueUuids)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            // Every uuid must have resolved. A silently shorter axis is a
            // smaller variant set than the seller asked for.
            if (count($ids) !== count(array_unique($valueUuids))) {
                throw CatalogException::invalidAttributeValue($attribute->code);
            }

            if ($ids !== []) {
                $axes[] = $ids;
            }
        }

        return $axes;
    }

    /**
     * The cartesian product of the axes.
     *
     * No axes yields exactly one combination — the empty one — which is what
     * makes a "simple" product fall out of the same code path as a
     * twelve-variant one instead of needing a branch (ADR-039).
     *
     * @param  array<int, array<int, int>>  $axes
     * @return array<int, array<int, int>>
     */
    private function cartesian(array $axes): array
    {
        $result = [[]];

        foreach ($axes as $values) {
            $next = [];

            foreach ($result as $combination) {
                foreach ($values as $value) {
                    $next[] = [...$combination, $value];
                }
            }

            $result = $next;
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $valueIds
     */
    private function createVariant(Product $product, array $valueIds, string $key, int $position): ProductVariant
    {
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => $this->skus->generate($product, $key),
            'combination_key' => $key,
            // The axis-less variant of a simple product is the default one;
            // a product with axes has no single "default" to nominate.
            'is_default' => $valueIds === [],
            'position' => $position,
        ]);

        if ($valueIds !== []) {
            $defining = $this->attributes->variantDefiningFor($product->category);

            foreach ($valueIds as $valueId) {
                $attribute = $defining->first(
                    static fn (Attribute $candidate): bool => $candidate->values->contains('id', $valueId),
                );

                $variant->attributeValues()->attach($valueId, [
                    'attribute_id' => $attribute?->getKey(),
                ]);
            }
        }

        return $variant;
    }

    /**
     * Remove variants no longer in the selection — the destructive half of the
     * ruling, and OFF BY DEFAULT for that reason.
     *
     * Soft-deleted, never hard: a SKU that has been on a label, in a warehouse
     * or (later) on an order line must stay resolvable. The last variant is
     * never removed, because a product with none is not sellable (§3.3).
     *
     * @param  array<int, array<int, int>>  $combinations
     * @param  array<string, int>  $excluded
     */
    private function prune(Product $product, array $combinations, array $excluded): void
    {
        $wanted = [];

        foreach ($combinations as $valueIds) {
            $key = ProductVariant::combinationKeyFor($valueIds);

            if (! isset($excluded[$key])) {
                $wanted[] = $key;
            }
        }

        $doomed = $product->variants()->whereNotIn('combination_key', $wanted)->get();
        $surviving = $product->variants()->count() - $doomed->count();

        if ($surviving < 1) {
            throw CatalogException::productMustKeepOneVariant();
        }

        foreach ($doomed as $variant) {
            $variant->delete();
        }
    }
}
