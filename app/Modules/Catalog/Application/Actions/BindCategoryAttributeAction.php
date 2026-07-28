<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Contracts\AttributeRepositoryContract;
use App\Modules\Catalog\Domain\DTOs\BindCategoryAttributeDTO;
use App\Modules\Catalog\Domain\Events\CategoryUpdated;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Category;

/**
 * Binds an attribute to a category with its PER-CATEGORY flags (§2.3).
 *
 * This is where a category's attribute schema is actually built, and the flags
 * live on the binding rather than the attribute for one reason: Renk is a
 * variant axis in "Giyim" and a plain description in "Mobilya", while both
 * filter on the same shared set of colours.
 *
 * THE ONE FLAG THAT CANNOT BE OVERRIDDEN PER CATEGORY is variant-defining on a
 * non-enumerable type (ADR-039). A category may decline to use Renk as an axis;
 * no category may promote Ağırlık into one, because there is no finite set of
 * weights to multiply out.
 *
 * `updateExistingPivot`-style upsert: binding an already-bound attribute
 * re-configures it rather than failing on the UNIQUE index, because "set the
 * schema for this category" is the operation a Category Manager is performing.
 */
final class BindCategoryAttributeAction extends BaseAction
{
    public function __construct(private readonly AttributeRepositoryContract $attributes) {}

    public function handle(mixed ...$arguments): Category
    {
        /** @var Category $category */
        $category = $arguments[0];
        /** @var BindCategoryAttributeDTO $data */
        $data = $arguments[1];

        $attribute = $this->attributes->findOrFailByUuid($data->attributeUuid);

        if ($data->isVariantDefining && ! $attribute->canDefineVariants()) {
            throw CatalogException::attributeCannotDefineVariants($attribute->code);
        }

        $category->attributes()->syncWithoutDetaching([
            $attribute->getKey() => [
                'is_required' => $data->isRequired,
                'is_variant_defining' => $data->isVariantDefining,
                'is_filterable' => $data->isFilterable,
                'position' => $data->position ?? $category->attributes()->count(),
            ],
        ]);

        return $category->refresh();
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Category $result */
        CategoryUpdated::dispatch($result->getKey(), $result->uuid, $result->path, ['attributes']);
    }
}
