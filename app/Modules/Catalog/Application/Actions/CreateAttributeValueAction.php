<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Contracts\AttributeRepositoryContract;
use App\Modules\Catalog\Domain\DTOs\CreateAttributeValueDTO;
use App\Modules\Catalog\Domain\Events\AttributeUpdated;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\AttributeValue;

/**
 * Adds one allowed value to a `select` attribute (§2.3).
 *
 * REFUSES A NON-`select` ATTRIBUTE. Enumerated values only mean something for a
 * type whose values are enumerated; attaching "Kırmızı" to a Number attribute
 * would produce an option list the validator will never accept.
 *
 * Announced as `AttributeUpdated` rather than a `AttributeValueCreated` of its
 * own: a value has no meaning apart from its attribute, so what a consumer
 * actually needs to know is that the attribute's option set changed (§7).
 */
final class CreateAttributeValueAction extends BaseAction
{
    public function __construct(private readonly AttributeRepositoryContract $attributes) {}

    public function handle(mixed ...$arguments): AttributeValue
    {
        /** @var CreateAttributeValueDTO $data */
        $data = $arguments[0];

        $attribute = $this->attributes->findOrFailByUuid($data->attributeUuid);

        if (! $attribute->type->usesPredefinedValues()) {
            throw CatalogException::attributeDoesNotEnumerateValues($attribute->code);
        }

        $value = new AttributeValue;
        $value->fill([
            'attribute_id' => $attribute->getKey(),
            'value' => $data->value,
            'is_active' => $data->isActive,
            'position' => $data->position ?? $attribute->values()->count(),
        ]);
        $value->fillLocalized('label', $data->label);
        $value->save();

        // Hand the parent forward on the result rather than re-fetching it in
        // after(): strict mode makes a lazy load throw, and the attribute is
        // already in hand.
        $value->setRelation('attribute', $attribute);

        return $value;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var AttributeValue $result */
        $attribute = $result->attribute;

        AttributeUpdated::dispatch(
            $attribute->getKey(),
            $attribute->uuid,
            $attribute->code,
            ['values'],
        );
    }
}
