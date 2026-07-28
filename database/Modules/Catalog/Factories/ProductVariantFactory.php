<?php

declare(strict_types=1);

namespace Database\Modules\Catalog\Factories;

use App\Modules\Catalog\Domain\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductVariant>
 */
final class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'product_id' => ProductFactory::new(),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'barcode' => null,
            // Unique per row so two plain variants of one product do not trip
            // the (product_id, combination_key) index. A variant that really
            // has axes gets its key from ProductVariant::combinationKeyFor().
            'combination_key' => ProductVariant::KEY_SEPARATOR
                .fake()->unique()->numberBetween(1, 9999999)
                .ProductVariant::KEY_SEPARATOR,
            'is_default' => false,
            'position' => 0,
        ];
    }

    /**
     * The single axis-less variant of a "simple" product (ADR-039).
     */
    public function default(): static
    {
        return $this->state(fn (): array => [
            'is_default' => true,
            'combination_key' => ProductVariant::NO_AXES_KEY,
        ]);
    }

    /**
     * A variant standing for a specific set of attribute values, keyed exactly
     * as the generator would key it.
     *
     * @param  array<int, int>  $attributeValueIds
     */
    public function forValues(array $attributeValueIds): static
    {
        return $this->state(fn (): array => [
            'combination_key' => ProductVariant::combinationKeyFor($attributeValueIds),
        ]);
    }
}
