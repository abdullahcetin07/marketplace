<?php

declare(strict_types=1);

namespace Database\Modules\Catalog\Factories;

use App\Modules\Catalog\Domain\Enums\AttributeType;
use App\Modules\Catalog\Domain\Models\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attribute>
 */
final class AttributeFactory extends Factory
{
    protected $model = Attribute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->word().'_'.fake()->unique()->numberBetween(1, 99999);

        return [
            'uuid' => (string) Str::uuid(),
            'code' => $code,
            'name_tr' => Str::title($code),
            'name_en' => Str::title($code),
            // Select is the default because it is the only type that can define
            // variants (ADR-039), and variants are what most tests are about.
            'type' => AttributeType::Select,
            'is_variant_defining' => false,
            'is_filterable' => true,
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function ofType(AttributeType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function variantDefining(): static
    {
        return $this->state(fn (): array => [
            'type' => AttributeType::Select,
            'is_variant_defining' => true,
        ]);
    }

    /**
     * With `$count` enumerated values attached — the usual shape of a `select`
     * attribute a variant axis is built from.
     */
    public function withValues(int $count = 3): static
    {
        return $this->afterCreating(function (Attribute $attribute) use ($count): void {
            AttributeValueFactory::new()
                ->count($count)
                ->for($attribute)
                ->create();
        });
    }
}
