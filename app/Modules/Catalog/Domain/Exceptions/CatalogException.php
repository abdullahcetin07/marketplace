<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use Illuminate\Http\Response;

/**
 * The catalog refused an operation because it would break an integrity rule.
 *
 * EXPECTED DOMAIN REFUSALS, not incidents — publishing a product that is missing
 * a required attribute, attaching to a non-leaf category, generating a variant
 * that already exists. `BaseException::$reportable` stays false, so none of
 * these page anyone, and none of them are a 500.
 *
 * Every rule in §3 that a human can trip is a named constructor here rather than
 * an inline `throw new`, so the reasons are enumerable and the panels can render
 * a specific message instead of a generic failure.
 *
 * @see docs/modules/Catalog.md §3
 */
final class CatalogException extends BaseException
{
    protected int $status = Response::HTTP_UNPROCESSABLE_ENTITY;

    /**
     * §3.1 — the product is not in a state that allows this transition.
     */
    public static function invalidTransition(ProductStatus $from, ProductStatus $to): self
    {
        return self::make('This product cannot change state that way.')
            ->withContext([
                'reason' => 'invalid_transition',
                'from' => $from->value,
                'to' => $to->value,
            ]);
    }

    /**
     * §3.2 — products attach to a leaf only. A category with children is a
     * container; products sitting at both levels is how a taxonomy stops being
     * a reliable filter.
     */
    public static function categoryIsNotALeaf(string $categoryUuid): self
    {
        return self::make('A product can only be attached to a leaf category.')
            ->withContext([
                'reason' => 'category_not_leaf',
                'category_uuid' => $categoryUuid,
            ]);
    }

    /**
     * §3.2 — checked on PUBLISH, not on draft, so authoring stays incremental.
     *
     * @param array<int, string> $attributeCodes
     */
    public static function missingRequiredAttributes(array $attributeCodes): self
    {
        return self::make('This product is missing attributes its category requires.')
            ->withContext([
                'reason' => 'missing_required_attributes',
                'attributes' => array_values($attributeCodes),
            ]);
    }

    /**
     * §3.2 — the attribute is not bound to the product's category at all.
     */
    public static function attributeNotInCategorySchema(string $attributeCode): self
    {
        return self::make('That attribute does not apply to this product\'s category.')
            ->withContext([
                'reason' => 'attribute_not_in_schema',
                'attribute' => $attributeCode,
            ]);
    }

    /**
     * §3.2 — a `select` value must be one of the attribute's own values.
     */
    public static function invalidAttributeValue(string $attributeCode): self
    {
        return self::make('That value is not valid for this attribute.')
            ->withContext([
                'reason' => 'invalid_attribute_value',
                'attribute' => $attributeCode,
            ]);
    }

    /**
     * ADR-039 — only an enumerable (`select`) attribute can be a variant axis.
     */
    public static function attributeCannotDefineVariants(string $attributeCode): self
    {
        return self::make('Only a select attribute can define variants.')
            ->withContext([
                'reason' => 'attribute_cannot_define_variants',
                'attribute' => $attributeCode,
            ]);
    }

    /**
     * §3.3 — this combination of variant-defining values already exists on the
     * product. The database UNIQUE index is the backstop; this is the readable
     * refusal that gets there first.
     */
    public static function duplicateVariantCombination(string $combinationKey): self
    {
        return self::make('This product already has a variant with that combination.')
            ->withContext([
                'reason' => 'duplicate_variant_combination',
                'combination' => $combinationKey,
            ]);
    }

    /**
     * §13.4 — cartesian growth is multiplicative, so the generator refuses
     * rather than writing a table's worth of SKUs from one form submission.
     */
    public static function tooManyVariants(int $requested, int $max): self
    {
        return self::make('That selection would generate too many variants.')
            ->withContext([
                'reason' => 'variant_limit_exceeded',
                'requested' => $requested,
                'max' => $max,
            ]);
    }

    /**
     * §3.3 — every product has at least one variant. Removing the last one
     * would leave nothing for an Offer to reference.
     */
    public static function productMustKeepOneVariant(): self
    {
        return self::make('A product must keep at least one variant.')
            ->withContext(['reason' => 'last_variant']);
    }

    /**
     * §3.4 — the GTIN is the primary dedup key of a shared catalog. A collision
     * means the product is already here; the seller should offer it, not
     * re-create it.
     */
    public static function gtinAlreadyInCatalog(string $gtin, string $existingProductUuid): self
    {
        return self::make('A product with this barcode is already in the catalog.')
            ->withContext([
                'reason' => 'gtin_taken',
                'gtin' => $gtin,
                'product_uuid' => $existingProductUuid,
            ]);
    }

    /**
     * ADR-038 — a category cannot be moved beneath its own descendant; the
     * materialised path would become a cycle.
     */
    public static function categoryCannotBeItsOwnAncestor(): self
    {
        return self::make('A category cannot be moved inside itself.')
            ->withContext(['reason' => 'category_cycle']);
    }

    /**
     * §3.5 — deactivating a branch that still has active children would strand
     * them; archive the leaves first.
     */
    public static function categoryHasActiveChildren(string $categoryUuid): self
    {
        return self::make('Archive this category\'s subcategories first.')
            ->withContext([
                'reason' => 'category_has_children',
                'category_uuid' => $categoryUuid,
            ]);
    }

    /**
     * ADR-040 — a seller may only touch their own proposal. The membership wall
     * is a uuid comparison, because Catalog holds no Organization relation.
     */
    public static function notTheProposingSeller(): self
    {
        return self::make(__('errors.forbidden'))
            ->withContext(['reason' => 'not_the_proposer']);
    }
}
