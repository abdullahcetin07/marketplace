<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contracts;

use App\Modules\Catalog\Domain\Models\Category;
use Illuminate\Database\Eloquent\Collection;

/**
 * The persistence port for the category tree (ADR-038).
 *
 * `descendantsOf` and `subtreeOf` are the two reads the materialised `path`
 * exists for (§13.1); `rebuildSubtreePaths` is the write it costs — a move
 * rewrites the paths beneath the moved node, which is bounded but is not a
 * single-row update, so it is named here rather than hidden in an action.
 *
 * @see App\Modules\Catalog\Infrastructure\Repositories\CategoryRepository
 */
interface CategoryRepositoryContract
{
    public function findByUuid(string $uuid): ?Category;

    public function findOrFailByUuid(string $uuid): Category;

    public function findBySlug(string $slug): ?Category;

    public function slugExists(string $slug, ?int $exceptId = null): bool;

    /**
     * The whole tree, ordered for rendering (depth, then position).
     *
     * @return Collection<int, Category>
     */
    public function tree(bool $activeOnly = false): Collection;

    /**
     * @return Collection<int, Category>
     */
    public function roots(bool $activeOnly = false): Collection;

    /**
     * The categories a product may attach to (§3.2).
     *
     * @return Collection<int, Category>
     */
    public function leaves(bool $activeOnly = true): Collection;

    /**
     * Every node beneath this one, at any depth — one prefix scan on `path`.
     *
     * @return Collection<int, Category>
     */
    public function descendantsOf(Category $category): Collection;

    /**
     * The node and everything beneath it, ordered root-first — the set a move
     * has to rewrite.
     *
     * @return Collection<int, Category>
     */
    public function subtreeOf(Category $category): Collection;

    /**
     * Recompute `path` and `depth` for a node's whole subtree after a move.
     *
     * @return int the number of rows rewritten
     */
    public function rebuildSubtreePaths(Category $category): int;

    /**
     * The next free position among a parent's children, so a new node lands at
     * the end rather than colliding with a sibling.
     */
    public function nextPositionUnder(?int $parentId): int;
}
