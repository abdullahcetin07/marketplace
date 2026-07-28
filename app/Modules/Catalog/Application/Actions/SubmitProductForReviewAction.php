<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Events\ProductSubmittedForReview;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Product;

/**
 * The seller hands a proposal to the moderation queue (§3.1/§5).
 *
 * REQUIRES AT LEAST ONE VARIANT (§3.3, ADR-039). The variant is the unit an
 * Offer will reference, so a product with none is not a thing anyone could ever
 * sell — and submitting one wastes a moderator's time on something that cannot
 * be published. This is the earliest point the rule can be enforced without
 * making the draft form unusable.
 *
 * Missing REQUIRED ATTRIBUTES are still not checked here (§3.2 — rejected on
 * publish, not on draft). A moderator seeing an incomplete product and sending
 * it back with a reason is the designed path; it is more useful to a seller
 * than a form error, because the moderator can say which attribute and why.
 */
final class SubmitProductForReviewAction extends BaseAction
{
    public function handle(mixed ...$arguments): Product
    {
        /** @var Product $product */
        $product = $arguments[0];

        if (! $product->status->canTransitionTo(ProductStatus::PendingReview)) {
            throw CatalogException::invalidTransition($product->status, ProductStatus::PendingReview);
        }

        if (! $product->variants()->exists()) {
            throw CatalogException::productMustKeepOneVariant();
        }

        $product->forceFill([
            'status' => ProductStatus::PendingReview,
            'submitted_at' => now(),
            // The previous verdict is cleared on re-submission: leaving a stale
            // "blurry photographs" note attached would show the seller a reason
            // for a decision that has been superseded.
            'moderation_reason' => null,
        ])->save();

        return $product;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Product $result */
        ProductSubmittedForReview::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->category_id,
            $result->proposed_by_org_uuid,
        );
    }
}
