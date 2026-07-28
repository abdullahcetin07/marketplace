<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Contracts\AttributeRepositoryContract;
use App\Modules\Catalog\Domain\DTOs\ModerationDecisionDTO;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Events\ProductPublished;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Product;

/**
 * A moderator approves a proposal; it goes live in the shared catalog (§3.1).
 *
 * APPROVING CREATES NOTHING (§5). Unlike a Store Opening Request, where approval
 * produces a Store, the product simply moves to `Published` — which is why the
 * moderation state lives on the product and there is no request entity here.
 *
 * THIS IS WHERE SCHEMA CONFORMANCE IS ENFORCED (§3.2), and the placement is the
 * decision: required attributes are checked on PUBLISH, not on draft or submit,
 * so authoring stays incremental. The gate sits at the last possible moment —
 * the point where the product becomes something other sellers and, later,
 * buyers will rely on.
 *
 * The check is deliberately not silent-fixable: it names the missing attribute
 * codes, so a moderator can send the product back with something actionable
 * rather than "invalid".
 */
final class PublishProductAction extends BaseAction
{
    public function __construct(private readonly AttributeRepositoryContract $attributes) {}

    public function handle(mixed ...$arguments): Product
    {
        /** @var Product $product */
        $product = $arguments[0];
        /** @var ModerationDecisionDTO|null $decision */
        $decision = $arguments[1] ?? null;

        if (! $product->status->canTransitionTo(ProductStatus::Published)) {
            throw CatalogException::invalidTransition($product->status, ProductStatus::Published);
        }

        $this->guardSchemaConformance($product);

        $product->forceFill([
            'status' => ProductStatus::Published,
            'moderated_at' => now(),
            'moderated_by' => $decision?->moderatedBy,
            // An approval carries no reason; clearing it stops a previous
            // rejection's note surviving onto a published product.
            'moderation_reason' => null,
            // First publication only. Re-publishing after an archive would
            // otherwise rewrite the date the catalog entry actually became
            // public, which is what SEO and "new arrivals" read.
            'published_at' => $product->published_at ?? now(),
        ])->save();

        return $product;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Product $result */
        ProductPublished::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->category_id,
            $result->proposed_by_org_uuid,
            $result->moderated_by,
        );
    }

    /**
     * Every attribute the product's category marks required must have a value
     * (§3.2).
     *
     * Compared by attribute id against the pivot rather than by re-reading each
     * value, so the whole check is two queries regardless of how many
     * attributes the category binds.
     */
    private function guardSchemaConformance(Product $product): void
    {
        $required = $this->attributes->requiredFor($product->category);

        if ($required->isEmpty()) {
            return;
        }

        $provided = $product->attributes()->get()
            ->map(static fn (Attribute $attribute): int => (int) $attribute->getKey())
            ->all();

        $missing = $required
            ->reject(static fn (Attribute $attribute): bool => in_array((int) $attribute->getKey(), $provided, true))
            ->map(static fn (Attribute $attribute): string => $attribute->code)
            ->values()
            ->all();

        if ($missing !== []) {
            throw CatalogException::missingRequiredAttributes($missing);
        }
    }
}
