<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Contracts\CategoryRepositoryContract;
use App\Modules\Catalog\Domain\Events\CategoryUpdated;
use App\Modules\Catalog\Domain\Models\Category;

/**
 * Re-orders siblings by rewriting their `position` (§4).
 *
 * TAKES THE WHOLE ORDERED LIST, not a "move this one up". A drag-and-drop tree
 * hands back the new order in full, and applying it wholesale means the result
 * cannot drift: positions are re-numbered 0..n-1 every time, so repeated
 * reordering never accumulates gaps or ties.
 *
 * Ordering only — nothing here re-parents. A node that moves to a different
 * parent goes through UpdateCategoryAction, which rewrites paths.
 */
final class ReorderCategoriesAction extends BaseAction
{
    public function __construct(private readonly CategoryRepositoryContract $categories) {}

    /**
     * @return array<int, Category>
     */
    public function handle(mixed ...$arguments): array
    {
        /** @var array<int, string> $orderedUuids */
        $orderedUuids = $arguments[0];

        $reordered = [];

        foreach (array_values($orderedUuids) as $position => $uuid) {
            $category = $this->categories->findOrFailByUuid($uuid);
            $category->forceFill(['position' => $position])->save();
            $reordered[] = $category;
        }

        return $reordered;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var array<int, Category> $result */
        foreach ($result as $category) {
            CategoryUpdated::dispatch(
                $category->getKey(),
                $category->uuid,
                $category->path,
                ['position'],
            );
        }
    }
}
