<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Events\OfferReinstated;
use App\Modules\Offer\Domain\Exceptions\OfferException;
use App\Modules\Offer\Domain\Models\Offer;

/**
 * An admin lifts a suspension (§3.1).
 *
 * RESTORES `status_before_suspension`, NOT `Active`. A seller whose paused offer
 * was then suspended gets their pause back: lifting a suspension undoes the
 * ADMIN's action, it does not override the seller's. Guessing Active here would
 * silently re-list something the seller had deliberately taken down — the same
 * reasoning as ReinstateStoreAction.
 *
 * The suspension metadata is cleared, so a later suspension records its own
 * reason rather than inheriting a stale one.
 */
final class ReinstateOfferAction extends BaseAction
{
    /**
     * Captured for the event: the write clears the column it came from, and a
     * consumer needs to know what the offer went BACK to.
     */
    private OfferStatus $restored = OfferStatus::Paused;

    private ?int $reinstatedBy = null;

    public function handle(mixed ...$arguments): Offer
    {
        /** @var Offer $offer */
        $offer = $arguments[0];
        /** @var User|null $admin */
        $admin = $arguments[1] ?? null;
        $reason = $arguments[2] ?? null;

        if ($offer->status !== OfferStatus::Suspended) {
            throw OfferException::notSuspended();
        }

        // Paused is the safe fallback for a row whose prior state was somehow
        // never recorded: it is visible to the seller and sells nothing, so a
        // missing value cannot silently put an offer back in the buy box.
        $restored = $offer->status_before_suspension ?? OfferStatus::Paused;

        AuditContext::withReasonFor($reason, function () use ($offer, $restored): void {
            $offer->forceFill([
                'status' => $restored,
                'status_before_suspension' => null,
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
            ])->save();
        });

        $this->restored = $restored;
        $this->reinstatedBy = $admin?->getKey();

        return $offer;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Offer $result */
        OfferReinstated::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->variant_uuid,
            $result->product_uuid,
            $this->restored->value,
            $this->reinstatedBy,
        );
    }
}
