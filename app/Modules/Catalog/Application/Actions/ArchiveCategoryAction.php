<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Events\CategoryArchived;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Category;

/**
 * Withdraws a category from the taxonomy.
 *
 * ARCHIVING IS `is_active = false`, NOT A DELETE. A category is lookup-style
 * reference data (ADR-015), and products point at it: hard-deleting would
 * either orphan them or cascade a whole branch of the catalog away. Deactivating
 * hides it from authoring while every existing product still resolves.
 *
 * REFUSES A BRANCH WITH ACTIVE CHILDREN (§3.5). Deactivating a parent while its
 * children stay active would leave those children reachable through a category
 * nobody can see — visible in the tree, unusable in a form. Archive the leaves
 * first, deliberately, rather than have one click silently retire a subtree.
 */
final class ArchiveCategoryAction extends BaseAction
{
    public function handle(mixed ...$arguments): Category
    {
        /** @var Category $category */
        $category = $arguments[0];

        if ($category->children()->where('is_active', true)->exists()) {
            throw CatalogException::categoryHasActiveChildren($category->uuid);
        }

        $category->forceFill(['is_active' => false])->save();

        return $category;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Category $result */
        CategoryArchived::dispatch($result->getKey(), $result->uuid, $result->path);
    }
}
