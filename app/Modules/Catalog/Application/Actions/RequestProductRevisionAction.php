<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\DTOs\ModerationDecisionDTO;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Events\ProductRevisionRequested;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Product;

/**
 * A moderator sends a proposal back with a reason (`NeedsRevision`, §3.1/§5).
 *
 * THE HUMANE MIDDLE PATH, and the reason `NeedsRevision` exists as a distinct
 * case: most refused products have a fixable problem — a blurry photograph, a
 * wrong category, a missing attribute — and rejecting them outright throws away
 * a seller's work over something they would happily correct. This mirrors the
 * KYC document pattern already shipped in Organization.
 *
 * The product returns to a seller-editable state and re-enters the queue on
 * re-submission, so nothing about it is lost.
 *
 * THE REASON IS REQUIRED and is the entire point: "needs revision" with no note
 * is not actionable, it is just a rejection that wastes another round trip.
 */
final class RequestProductRevisionAction extends BaseAction
{
    public function handle(mixed ...$arguments): Product
    {
        /** @var Product $product */
        $product = $arguments[0];
        /** @var ModerationDecisionDTO $decision */
        $decision = $arguments[1];

        if (! $product->status->canTransitionTo(ProductStatus::NeedsRevision)) {
            throw CatalogException::invalidTransition($product->status, ProductStatus::NeedsRevision);
        }

        $reason = trim((string) $decision->reason);

        if ($reason === '') {
            throw CatalogException::moderationReasonRequired(ProductStatus::NeedsRevision);
        }

        $product->forceFill([
            'status' => ProductStatus::NeedsRevision,
            'moderated_at' => now(),
            'moderated_by' => $decision->moderatedBy,
            'moderation_reason' => $reason,
        ])->save();

        return $product;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Product $result */
        ProductRevisionRequested::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->proposed_by_org_uuid,
            (string) $result->moderation_reason,
            $result->moderated_by,
        );
    }
}
