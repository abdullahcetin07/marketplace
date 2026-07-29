<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Contracts\CategoryRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\CategorySlugGeneratorContract;
use App\Modules\Catalog\Domain\DTOs\CreateCategoryDTO;
use App\Modules\Catalog\Domain\Events\CategoryCreated;
use App\Modules\Catalog\Domain\Models\Category;

/**
 * Adds a node to the platform taxonomy (ADR-038).
 *
 * TWO WRITES, ONE TRANSACTION. The materialised `path` contains the node's own
 * id, which does not exist until the row is inserted — so the row is created
 * with a placeholder and the path written back immediately. Both inside the
 * action's transaction, so a node is never visible with a path that does not
 * locate it.
 *
 * The slug comes from the generator behind its contract, never from the
 * aggregate, and defaults to the Turkish name — `Str::slug` transliterates, so
 * "Kadın Giyim" becomes "kadin-giyim".
 */
final class CreateCategoryAction extends BaseAction
{
    public function __construct(
        private readonly CategoryRepositoryContract $categories,
        private readonly CategorySlugGeneratorContract $slugs,
    ) {}

    public function handle(mixed ...$arguments): Category
    {
        /** @var CreateCategoryDTO $data */
        $data = $arguments[0];

        $parent = $data->parentUuid === null
            ? null
            : $this->categories->findOrFailByUuid($data->parentUuid);

        $requested = $data->slug ?? ($data->name[config('catalog.locales')[0] ?? 'tr'] ?? '');

        $category = new Category;
        $category->fill([
            'parent_id' => $parent?->getKey(),
            'path' => Category::PATH_SEPARATOR,
            'depth' => Category::depthFor($parent),
            'slug' => $this->slugs->forCategory((string) $requested),
            'is_active' => $data->isActive,
            'accepts_products' => $data->acceptsProducts,
            'position' => $data->position ?? $this->categories->nextPositionUnder($parent?->getKey()),
        ]);
        $category->fillLocalized('name', $data->name);
        $category->save();

        // Now that the id exists, the path can be what it claims to be.
        $category->forceFill([
            'path' => Category::pathFor($parent, (int) $category->getKey()),
        ])->save();

        return $category;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Category $result */
        CategoryCreated::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->parent_id,
            $result->path,
            $result->slug,
        );
    }
}
