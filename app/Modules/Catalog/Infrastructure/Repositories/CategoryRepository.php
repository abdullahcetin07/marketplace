<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Repositories;

use App\Modules\Catalog\Domain\Contracts\CategoryRepositoryContract;
use App\Modules\Catalog\Domain\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Reads and rewrites the category tree.
 *
 * NO DEFAULT `$with` HERE, deliberately. The other repositories declare eager
 * loads because strict mode makes a lazy load throw; this one is asked for whole
 * trees, and eager-loading `parent` on every node of a 400-node tree would fetch
 * each node twice. Callers that need the schema ask for it explicitly.
 *
 * `rebuildSubtreePaths` is the write the materialised path costs (§13.1). It is
 * a loop rather than one recursive UPDATE because the two engines disagree about
 * recursive CTE syntax and a category move is rare; the loop is bounded to the
 * moved subtree and runs inside the action's transaction.
 *
 * @see App\Modules\Catalog\Domain\Contracts\CategoryRepositoryContract
 */
final class CategoryRepository implements CategoryRepositoryContract
{
    public function findByUuid(string $uuid): ?Category
    {
        return Category::query()->where('uuid', $uuid)->first();
    }

    public function findOrFailByUuid(string $uuid): Category
    {
        $category = $this->findByUuid($uuid);

        if ($category === null) {
            throw (new ModelNotFoundException)->setModel(Category::class, [$uuid]);
        }

        return $category;
    }

    public function findBySlug(string $slug): ?Category
    {
        return Category::query()->where('slug', $slug)->first();
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return Category::query()
            ->where('slug', $slug)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    /**
     * @return Collection<int, Category>
     */
    public function tree(bool $activeOnly = false): Collection
    {
        return Category::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('depth')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Category>
     */
    public function roots(bool $activeOnly = false): Collection
    {
        return Category::query()
            ->roots()
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('position')
            ->get();
    }

    /**
     * @return Collection<int, Category>
     */
    public function leaves(bool $activeOnly = true): Collection
    {
        return Category::query()
            ->leaves()
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('path')
            ->get();
    }

    /**
     * @return Collection<int, Category>
     */
    public function descendantsOf(Category $category): Collection
    {
        return Category::query()
            ->descendantsOf($category)
            ->orderBy('depth')
            ->orderBy('position')
            ->get();
    }

    /**
     * @return Collection<int, Category>
     */
    public function subtreeOf(Category $category): Collection
    {
        return Category::query()
            ->where('path', 'like', $category->path.'%')
            ->orderBy('depth')
            ->orderBy('position')
            ->get();
    }

    /**
     * Recompute `path` and `depth` beneath a moved node.
     *
     * Walks depth-first from the node itself, so every parent is rewritten
     * before its children read it — which is what makes one pass enough. The
     * moved node's OWN path must already be correct when this is called; the
     * action sets it, because only the action knows the new parent.
     */
    public function rebuildSubtreePaths(Category $category): int
    {
        $rewritten = 0;
        $queue = [$category];

        while ($queue !== []) {
            $current = array_shift($queue);

            /** @var Collection<int, Category> $children */
            $children = Category::query()->where('parent_id', $current->getKey())->get();

            foreach ($children as $child) {
                $child->forceFill([
                    'path' => Category::pathFor($current, (int) $child->getKey()),
                    'depth' => Category::depthFor($current),
                ])->save();

                $rewritten++;
                $queue[] = $child;
            }
        }

        return $rewritten;
    }

    public function nextPositionUnder(?int $parentId): int
    {
        $max = Category::query()
            ->when(
                $parentId === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $parentId),
            )
            ->max('position');

        return $max === null ? 0 : ((int) $max) + 1;
    }
}
