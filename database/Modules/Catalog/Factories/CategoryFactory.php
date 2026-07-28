<?php

declare(strict_types=1);

namespace Database\Modules\Catalog\Factories;

use App\Modules\Catalog\Domain\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'uuid' => (string) Str::uuid(),
            'parent_id' => null,
            // A placeholder: the real path needs the id, which does not exist
            // until the row is inserted, so `configure()` rewrites it after
            // creation. Never hand-build a path — Category::pathFor() owns the
            // format.
            'path' => Category::PATH_SEPARATOR,
            'depth' => 0,
            'name_tr' => Str::title($name),
            'name_en' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'is_active' => true,
            'position' => 0,
        ];
    }

    /**
     * Fix up the materialised path once the row has an id.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Category $category): void {
            $parent = $category->parent_id === null
                ? null
                : Category::query()->find($category->parent_id);

            $category->forceFill([
                'path' => Category::pathFor($parent, (int) $category->getKey()),
                'depth' => Category::depthFor($parent),
            ])->save();
        });
    }

    /**
     * A child of the given node. The path and depth follow from the parent, so
     * a test never has to spell either out.
     */
    public function childOf(Category $parent): static
    {
        return $this->state(fn (): array => [
            'parent_id' => $parent->getKey(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
