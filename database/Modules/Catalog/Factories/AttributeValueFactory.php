<?php

declare(strict_types=1);

namespace Database\Modules\Catalog\Factories;

use App\Modules\Catalog\Domain\Models\AttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AttributeValue>
 */
final class AttributeValueFactory extends Factory
{
    protected $model = AttributeValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = fake()->unique()->word().'-'.fake()->unique()->numberBetween(1, 99999);

        return [
            'uuid' => (string) Str::uuid(),
            'attribute_id' => AttributeFactory::new(),
            'value' => $value,
            'label_tr' => Str::title($value),
            'label_en' => Str::title($value),
            'is_active' => true,
            'position' => 0,
        ];
    }
}
