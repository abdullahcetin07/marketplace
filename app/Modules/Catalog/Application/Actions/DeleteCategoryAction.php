<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Events\CategoryArchived;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Category;

/**
 * Removes a category that never held anything — the mis-created one.
 *
 * WHY A DELETE EXISTS AT ALL, when archiving is the rule. `is_active = false`
 * is right for a category that MEANT something: products point at it and the
 * tree is a public URL space, so withdrawing it must not orphan or 404 anything
 * (ArchiveCategoryAction). But a node typed wrong five minutes ago has no
 * products, no children and no history — archiving it leaves permanent litter
 * in a tree whose whole value is that it reads cleanly.
 *
 * BOTH GUARDS, AND THEY ARE NOT THE SAME GUARD:
 *
 *   - No PRODUCTS. Deleting under one would orphan a live catalog entry, which
 *     is why archiving exists in the first place.
 *   - No CHILDREN — active OR inactive. The FK is `restrictOnDelete`, so the
 *     database would refuse an active child anyway; the check is here so the
 *     Category Manager gets a sentence instead of a constraint violation, and
 *     so an INACTIVE child (which the FK also protects but which reads as
 *     "empty" in the UI) is caught with the same message.
 *
 * A HARD DELETE, deliberately. A soft-deleted taxonomy node would keep its
 * globally-unique slug reserved (§3.5) — so the Category Manager could not
 * re-create the node they just fixed the spelling of, for no benefit, since
 * there is nothing to restore.
 *
 * @see docs/modules/Catalog.md §3.2
 */
final class DeleteCategoryAction extends BaseAction
{
    /**
     * Identity captured before the row went away, so the event can still say
     * what was removed.
     *
     * @var array{id: int, uuid: string, path: string}
     */
    private array $deleted = ['id' => 0, 'uuid' => '', 'path' => ''];

    public function handle(mixed ...$arguments): mixed
    {
        /** @var Category $category */
        $category = $arguments[0];

        if ($category->products()->exists()) {
            throw CatalogException::categoryStillHasProducts($category->uuid);
        }

        // Any child at all, not just an active one — see the class docblock.
        if ($category->children()->exists()) {
            throw CatalogException::categoryHasChildren($category->uuid);
        }

        $this->deleted = [
            'id' => (int) $category->getKey(),
            'uuid' => $category->uuid,
            'path' => $category->path,
        ];

        $category->delete();

        return null;
    }

    /**
     * REUSES `CategoryArchived` rather than adding a `CategoryDeleted`.
     *
     * Every consumer of that event asks the same question — "this node is gone
     * from the taxonomy, stop offering it" — and the answer is identical
     * whether the row was deactivated or removed. A second event would mean
     * every current and future listener growing a branch that does the same
     * thing twice.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        CategoryArchived::dispatch($this->deleted['id'], $this->deleted['uuid'], $this->deleted['path']);
    }
}
