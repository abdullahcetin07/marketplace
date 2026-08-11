<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Contracts\CategoryRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\CategorySlugGeneratorContract;
use App\Modules\Catalog\Domain\DTOs\UpdateCategoryDTO;
use App\Modules\Catalog\Domain\Events\CategoryUpdated;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Category;

/**
 * Edits a taxonomy node — including the expensive edit, a MOVE.
 *
 * Re-parenting is what the materialised path costs (§13.1). The node's own path
 * is recomputed from its new parent, then the whole subtree beneath it is
 * rewritten. Bounded to that subtree and inside one transaction, so the tree is
 * never observed half-moved.
 *
 * THE CYCLE GUARD: a node may not move beneath its own descendant. Without it
 * the path becomes self-referential and the branch vanishes from every prefix
 * scan — a corruption that reads as data loss rather than as an error. Checked
 * against the CURRENT path, before anything is written.
 *
 * PATCH semantics via the DTO's `present` list: an edit form that omits `slug`
 * leaves it alone rather than blanking a live URL (§3.5 — slugs are stable).
 */
final class UpdateCategoryAction extends BaseAction
{
    public function __construct(
        private readonly CategoryRepositoryContract $categories,
        private readonly CategorySlugGeneratorContract $slugs,
    ) {}

    public function handle(mixed ...$arguments): Category
    {
        /** @var Category $category */
        $category = $arguments[0];
        /** @var UpdateCategoryDTO $data */
        $data = $arguments[1];

        $parent = null;
        $moved = false;

        if ($data->has('parentUuid')) {
            $parent = $data->parentUuid === null
                ? null
                : $this->categories->findOrFailByUuid($data->parentUuid);

            $this->guardAgainstCycle($category, $parent);

            $moved = $category->parent_id !== $parent?->getKey();

            if ($moved) {
                $category->parent_id = $parent?->getKey();
                $category->path = Category::pathFor($parent, (int) $category->getKey());
                $category->depth = Category::depthFor($parent);
            }
        }

        if ($data->name !== []) {
            $category->fillLocalized('name', $data->name);
        }

        if ($data->has('slug') && $data->slug !== null && $data->slug !== $category->slug) {
            $category->slug = $this->slugs->forCategory($data->slug, (int) $category->getKey());
        }

        if ($data->has('isActive') && $data->isActive !== null) {
            $category->is_active = $data->isActive;
        }

        /*
        | ADR-047. Closing a category under existing products would leave them
        | attached somewhere that now refuses attachment — valid on disk,
        | invalid on their next edit, and invisible until a seller hit it. The
        | guard lives HERE, not only in the form, because the form is one of
        | several ways this action is reached.
        */
        if ($data->has('acceptsProducts') && $data->acceptsProducts !== null) {
            if (! $data->acceptsProducts && $category->products()->exists()) {
                throw CatalogException::categoryStillHasProducts($category->uuid);
            }

            $category->accepts_products = $data->acceptsProducts;
        }

        if ($data->has('position') && $data->position !== null) {
            $category->position = $data->position;
        }

        /*
        | **A HUMAN TOUCHED IT, SO IT IS THEIRS NOW** (ADR-075, A4). The marker
        | means precisely "the import made this and nobody has curated it since",
        | and this is the moment that stops being true. Without clearing it, a
        | later re-import could reopen a category a Category Manager had
        | deliberately closed — ADR-047 broken through the back door, by a
        | spreadsheet, silently.
        |
        | UNCONDITIONAL, not "only when accepts_products changed": renaming or
        | re-parenting a node is curation too, and a rule that tried to decide
        | which edits count would be a rule somebody has to keep correct.
        */
        $category->created_by_import = false;

        $category->save();

        if ($moved) {
            // The node's own path is correct now; everything beneath follows.
            $this->categories->rebuildSubtreePaths($category);
        }

        return $category;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Category $result */
        // Read the change set off the model rather than tracking it in a
        // property: the action is resolved from the container and must stay
        // stateless, and Eloquent already knows exactly what the save changed.
        CategoryUpdated::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->path,
            array_keys($result->getChanges()),
        );
    }

    /**
     * A node cannot become its own ancestor.
     *
     * The new parent's path STARTING WITH the node's own path is exactly that
     * condition — and it covers "move into itself" too, since a path always
     * starts with itself.
     */
    private function guardAgainstCycle(Category $category, ?Category $parent): void
    {
        if ($parent === null) {
            return;
        }

        if (str_starts_with($parent->path, $category->path)) {
            throw CatalogException::categoryCannotBeItsOwnAncestor();
        }
    }
}
