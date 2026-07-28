<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\DTOs\ModerationDecisionDTO;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Events\ProductRejected;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Product;

/**
 * A moderator refuses a proposal outright (§3.1).
 *
 * DISTINCT FROM RequestProductRevisionAction. Rejection is for a proposal that
 * cannot be accepted as conceived — a duplicate of an existing catalog entry, a
 * prohibited item. "The photographs are blurry" is a revision request, not a
 * rejection, and conflating them trains sellers to read every refusal as final.
 *
 * NOT A DEAD END: `Rejected` can transition back to `Draft`, so a seller can
 * rework the idea rather than losing the work.
 *
 * THE REASON IS REQUIRED. A rejection with no stated cause is the fastest way to
 * lose a merchant, and the seller is shown this string.
 */
final class RejectProductAction extends BaseAction
{
    public function handle(mixed ...$arguments): Product
    {
        /** @var Product $product */
        $product = $arguments[0];
        /** @var ModerationDecisionDTO $decision */
        $decision = $arguments[1];

        if (! $product->status->canTransitionTo(ProductStatus::Rejected)) {
            throw CatalogException::invalidTransition($product->status, ProductStatus::Rejected);
        }

        $reason = trim((string) $decision->reason);

        if ($reason === '') {
            throw CatalogException::moderationReasonRequired(ProductStatus::Rejected);
        }

        $product->forceFill([
            'status' => ProductStatus::Rejected,
            'moderated_at' => now(),
            'moderated_by' => $decision->moderatedBy,
            'moderation_reason' => $reason,
        ])->save();

        return $product;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Product $result */
        ProductRejected::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->proposed_by_org_uuid,
            (string) $result->moderation_reason,
            $result->moderated_by,
        );
    }
}
