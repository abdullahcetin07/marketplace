<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Events\OfferResumed;
use App\Modules\Offer\Domain\Exceptions\OfferException;
use App\Modules\Offer\Domain\Models\Offer;

/**
 * A paused offer goes live again (§3.1).
 *
 * `paused_by_cascade` is CLEARED on resume, whoever resumed it. Leaving it set
 * would mean a later product archive/re-publish round trip reactivated an offer
 * the seller had since paused deliberately — the flag describes why it is paused
 * *now*, not a permanent label.
 *
 * Nothing here consults stock. An offer resumed with none simply does not win
 * the buy box until it is restocked; refusing to resume would be inventing a
 * precondition the buy box already enforces at read time (ADR-043/045).
 */
final class ResumeOfferAction extends BaseAction
{
    /**
     * Whether the product lifecycle resumed this, rather than the seller —
     * captured for the event, since the column is cleared by the write itself.
     */
    private bool $byCascade = false;

    public function handle(mixed ...$arguments): Offer
    {
        /** @var Offer $offer */
        $offer = $arguments[0];
        $reason = $arguments[1] ?? null;
        $byCascade = (bool) ($arguments[2] ?? false);

        if (! $offer->status->canSellerTransitionTo(OfferStatus::Active)) {
            throw OfferException::invalidTransition($offer->status, OfferStatus::Active);
        }

        AuditContext::withReasonFor($reason, function () use ($offer): void {
            $offer->forceFill([
                'status' => OfferStatus::Active,
                'paused_by_cascade' => false,
            ])->save();
        });

        $this->byCascade = $byCascade;

        return $offer;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Offer $result */
        OfferResumed::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->variant_uuid,
            $result->product_uuid,
            $this->byCascade,
        );
    }
}
