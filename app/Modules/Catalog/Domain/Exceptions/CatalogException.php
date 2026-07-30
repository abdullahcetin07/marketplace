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
     * §5 — a rejection or a revision request must say why.
     *
     * Enforced in the action, not left to the form, because the reason is shown
     * to the seller and travels on the event. A refusal with no stated cause is
     * the fastest way to lose a merchant, and "needs revision" with no note is
     * not actionable at all — it is a rejection that costs an extra round trip.
     * Approval needs no reason, which is why this is a rule about two outcomes
     * rather than a non-null column.
     */
    public static function moderationReasonRequired(ProductStatus $outcome): self
    {
        return self::make('This decision needs a reason the seller can act on.')
            ->withContext([
                'reason' => 'moderation_reason_required',
                'outcome' => $outcome->value,
            ]);
    }

    /**
     * §3.2 — a category with a subtree beneath it cannot be deleted.
     *
     * Distinct from `categoryHasActiveChildren`, which guards ARCHIVING and
     * therefore only cares about active ones: deactivating a parent leaves its
     * inactive children exactly as unreachable as they already were, but
     * DELETING it would strand them under a parent that no longer exists.
     */
    public static function categoryHasChildren(string $categoryUuid): self
    {
        return self::make('This category has sub-categories; remove them first.')
            ->withContext([
                'reason' => 'category_has_children',
                'category_uuid' => $categoryUuid,
            ]);
    }

    /**
     * ADR-047 — a category cannot be closed to products while it holds some.
     *
     * The mirror of the attach rule. Turning the flag off under existing
     * products would leave them somewhere that refuses attachment: valid on
     * disk, invalid on their next edit, and invisible until a seller hit it.
     * Move the products first.
     */
    public static function categoryStillHasProducts(string $categoryUuid): self
    {
        return self::make('This category holds products; move them elsewhere before closing it.')
            ->withContext([
                'reason' => 'category_still_has_products',
                'category_uuid' => $categoryUuid,
            ]);
    }

    /**
     * §3.2, ADR-047 — the category is not flagged `accepts_products`.
     *
     * REPLACES the old "not a leaf" refusal. The distinction matters to whoever
     * reads the message: a container is not structurally wrong to sell from, it
     * simply has not been opened for products, and the Category Manager can
     * open it. "This must be a leaf" told a seller to go deeper; this tells
     * them to ask for the level they want.
     */
    public static function categoryDoesNotAcceptProducts(string $categoryUuid): self
    {
        return self::make('This category does not accept products. Choose one that does.')
            ->withContext([
                'reason' => 'category_does_not_accept_products',
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
     * §2.4 — this attribute is a variant AXIS in this category, so its value
     * belongs on a variant, not on the product.
     *
     * "This product is 100% cotton" and "this variant is size M" are different
     * claims; storing them the same way is how a catalog ends up unable to
     * answer either.
     */
    public static function attributeIsAVariantAxis(string $attributeCode): self
    {
        return self::make('That attribute defines variants — set it on the variant, not the product.')
            ->withContext([
                'reason' => 'attribute_is_a_variant_axis',
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
     * §2.3 — only a `select` attribute has an enumerated value set. Attaching an
     * option to a Text or Number attribute would build a list the validator can
     * never accept.
     *
     * Distinct from `attributeCannotDefineVariants()`: that one refuses a
     * variant AXIS, this one refuses a VALUE. Same underlying type rule, two
     * different things a human was trying to do, so two messages.
     */
    public static function attributeDoesNotEnumerateValues(string $attributeCode): self
    {
        return self::make('Only a select attribute can have predefined values.')
            ->withContext([
                'reason' => 'attribute_does_not_enumerate_values',
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
     * §2.4 (ADR-056) — a product needs a KDV bracket before it can be reviewed.
     *
     * Checked at SUBMISSION, alongside the one-variant rule and for the same
     * reason: without a rate, checkout has nothing to extract KDV with, so the
     * product could be approved into a catalog it can never be lawfully sold
     * from. The authoring form requires the field; this is the backstop for
     * anything that reached `Draft` without one — a pre-ADR-056 product whose
     * backfill never ran, or an import.
     */
    public static function productNeedsTaxRate(): self
    {
        return self::make('A product must have a KDV bracket before it can be submitted.')
            ->withContext(['reason' => 'missing_tax_rate']);
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
