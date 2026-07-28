<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Contracts\AttributeRepositoryContract;
use App\Modules\Catalog\Domain\DTOs\ProductAttributeValueDTO;
use App\Modules\Catalog\Domain\Enums\AttributeType;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Product;

/**
 * Sets a product's DESCRIPTIVE attribute values (§2.4).
 *
 * DESCRIPTIVE, not variant-defining. "This product is 100% cotton" belongs to
 * the product; "this variant is size M" belongs to the variant. Storing them the
 * same way is how a catalog ends up unable to answer either question, so this
 * action refuses an attribute the category marks variant-defining and points the
 * caller at the variant instead.
 *
 * A FULL REPLACEMENT, not a merge. The caller passes the complete set and gets
 * exactly that — because "set the attributes" is what a form submit means, and
 * a merge would make removing a value impossible through the UI.
 *
 * THREE THINGS ARE VALIDATED, all of them §3.2:
 *   1. the attribute is bound to the product's category at all;
 *   2. a `select` value is one of that attribute's own AttributeValues;
 *   3. a free value matches its type, normalised by the type so two products
 *      never disagree about what "true" or "1.50" looks like.
 *
 * Silently accepting free text for a `select` attribute is how a colour filter
 * acquires a "kirmizi", a "Kırmızı " and a "KIRMIZI" — which is why (2) throws
 * rather than falling back to the raw string.
 */
final class SetProductAttributesAction extends BaseAction
{
    public function __construct(private readonly AttributeRepositoryContract $attributes) {}

    public function handle(mixed ...$arguments): Product
    {
        /** @var Product $product */
        $product = $arguments[0];
        /** @var array<int, ProductAttributeValueDTO> $assignments */
        $assignments = $arguments[1];

        $schema = $this->attributes->schemaFor($product->category)->keyBy('uuid');

        $sync = [];

        foreach ($assignments as $assignment) {
            $attribute = $schema->get($assignment->attributeUuid);

            if (! $attribute instanceof Attribute) {
                throw CatalogException::attributeNotInCategorySchema($assignment->attributeUuid);
            }

            if ($this->isVariantAxis($attribute)) {
                throw CatalogException::attributeIsAVariantAxis($attribute->code);
            }

            $sync[$attribute->getKey()] = $attribute->type->usesPredefinedValues()
                ? $this->selectPivot($attribute, $assignment)
                : $this->freePivot($attribute, $assignment);
        }

        $product->attributes()->sync($sync);

        return $product->refresh();
    }

    /**
     * The pivot flag, not the attribute's global default — an attribute is an
     * axis only in the categories that say so (§2.3).
     */
    private function isVariantAxis(Attribute $attribute): bool
    {
        $pivot = $attribute->getRelation('pivot');

        return (bool) $pivot->getAttribute('is_variant_defining');
    }

    /**
     * @return array<string, mixed>
     */
    private function selectPivot(Attribute $attribute, ProductAttributeValueDTO $assignment): array
    {
        if ($assignment->valueUuid === null) {
            throw CatalogException::invalidAttributeValue($attribute->code);
        }

        $value = $attribute->values->firstWhere('uuid', $assignment->valueUuid);

        // Membership of THIS attribute's value set, not merely "a value that
        // exists" — otherwise Renk could be set to a Beden.
        if ($value === null) {
            throw CatalogException::invalidAttributeValue($attribute->code);
        }

        return ['attribute_value_id' => $value->getKey(), 'value' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function freePivot(Attribute $attribute, ProductAttributeValueDTO $assignment): array
    {
        $normalised = $attribute->type->normalise($assignment->value);

        if ($normalised === null) {
            throw CatalogException::invalidAttributeValue($attribute->code);
        }

        // A Number attribute given "abc" normalises to null and lands above;
        // a Boolean given anything at all resolves to '1' or '0'. Both are the
        // enum's business, not this action's.
        if ($attribute->type === AttributeType::Number && ! is_numeric($normalised)) {
            throw CatalogException::invalidAttributeValue($attribute->code);
        }

        return ['attribute_value_id' => null, 'value' => $normalised];
    }
}
