<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Events\OfferWithdrawn;
use App\Modules\Offer\Domain\Exceptions\OfferException;
use App\Modules\Offer\Domain\Models\Offer;

/**
 * The seller removes their offer for good (§3.1).
 *
 * A SOFT DELETE, AND BOTH HALVES MATTER. The status is written before the row is
 * deleted so the record says WHY it is gone rather than leaving a `deleted_at`
 * to be interpreted; the row survives because a future order line references the
 * offer it was bought from, and an order history pointing at a vanished listing
 * is unreadable.
 *
 * It also releases the (org, variant) slot: the partial unique index excludes
 * withdrawn rows precisely so a seller who withdrew can list the variant again
 * later (§3.2). Withdrawal is terminal for THIS offer, not a ban on selling the
 * thing.
 */
final class WithdrawOfferAction extends BaseAction
{
    public function handle(mixed ...$arguments): Offer
    {
        /** @var Offer $offer */
        $offer = $arguments[0];
        $reason = $arguments[1] ?? null;

        if (! $offer->status->canSellerTransitionTo(OfferStatus::Withdrawn)) {
            throw OfferException::invalidTransition($offer->status, OfferStatus::Withdrawn);
        }

        AuditContext::withReasonFor($reason, function () use ($offer): void {
            $offer->forceFill(['status' => OfferStatus::Withdrawn])->save();
            $offer->delete();
        });

        return $offer;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Offer $result */
        OfferWithdrawn::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->variant_uuid,
            $result->product_uuid,
            $result->selling_org_id,
            $result->selling_org_uuid,
        );
    }
}
