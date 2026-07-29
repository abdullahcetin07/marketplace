<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Events\OfferSuspended;
use App\Modules\Offer\Domain\Exceptions\OfferException;
use App\Modules\Offer\Domain\Models\Offer;

/**
 * An admin pulls a single offer (ADR-044).
 *
 * THE ONLY OVERSIGHT LEVER THIS MODULE HAS, and the counterweight to shipping
 * offers unmoderated: nothing is reviewed before it goes live, so an abusive
 * price is caught reactively. The Store/User suspension shape exactly — record
 * the prior state, the actor and the reason, so reinstating undoes this decision
 * instead of overwriting the seller's.
 *
 * Already-suspended and withdrawn offers refuse: re-suspending would overwrite
 * `status_before_suspension` with `Suspended` and destroy the very state a
 * reinstatement restores to.
 */
final class SuspendOfferAction extends BaseAction
{
    public function handle(mixed ...$arguments): Offer
    {
        /** @var Offer $offer */
        $offer = $arguments[0];
        /** @var User $admin */
        $admin = $arguments[1];
        $reason = $arguments[2] ?? null;

        if (in_array($offer->status, [OfferStatus::Suspended, OfferStatus::Withdrawn], true)) {
            throw OfferException::invalidTransition($offer->status, OfferStatus::Suspended);
        }

        AuditContext::withReasonFor($reason, function () use ($offer, $admin, $reason): void {
            $offer->forceFill([
                'status_before_suspension' => $offer->status,
                'status' => OfferStatus::Suspended,
                'suspended_at' => now(),
                'suspended_by' => $admin->getKey(),
                'suspension_reason' => $reason,
            ])->save();
        });

        return $offer;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Offer $result */
        OfferSuspended::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->variant_uuid,
            $result->product_uuid,
            $result->selling_org_uuid,
            $result->suspended_by,
            $result->suspension_reason,
        );
    }
}
