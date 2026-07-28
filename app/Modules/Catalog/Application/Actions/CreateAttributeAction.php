<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\DTOs\CreateAttributeDTO;
use App\Modules\Catalog\Domain\Events\AttributeCreated;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Attribute;

/**
 * Defines a new attribute (§2.3).
 *
 * REFUSES `is_variant_defining` ON A NON-ENUMERABLE TYPE (ADR-039). Variant
 * generation is a cartesian product over chosen values, and a cartesian needs a
 * finite axis — "Ağırlık: 2.4 kg" is a fact about a product, not something you
 * can multiply out. Caught here as well as on the binding, because an attribute
 * flagged variant-defining that no binding can honour is a trap for whoever
 * builds the next category.
 */
final class CreateAttributeAction extends BaseAction
{
    public function handle(mixed ...$arguments): Attribute
    {
        /** @var CreateAttributeDTO $data */
        $data = $arguments[0];

        if ($data->isVariantDefining && ! $data->type->canDefineVariants()) {
            throw CatalogException::attributeCannotDefineVariants($data->code);
        }

        $attribute = new Attribute;
        $attribute->fill([
            'code' => $data->code,
            'type' => $data->type,
            'is_variant_defining' => $data->isVariantDefining,
            'is_filterable' => $data->isFilterable,
            'is_active' => $data->isActive,
            'position' => $data->position ?? 0,
        ]);
        $attribute->fillLocalized('name', $data->name);
        $attribute->save();

        return $attribute;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Attribute $result */
        AttributeCreated::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->code,
            $result->type->value,
        );
    }
}
