<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use Illuminate\Http\Response;

/**
 * A fulfilment operation the domain refuses.
 *
 * EXPECTED REFUSALS, like every other module's — a parcel already shipped, an
 * order that is not this seller's. `$reportable` stays false: a seller
 * double-clicking "kargoya ver" is the system working.
 *
 * WITH ONE EXCEPTION THAT IS NOT AN ACCIDENT: `sellerCannotDeliver()` exists to
 * be thrown when something tries to mark a shipment delivered on the seller's
 * behalf. It should be unreachable — the panel offers no such control and the
 * policy denies it — which is exactly why it is worth having: the rule it defends
 * (ADR-064, a seller must not assert their own payday) is one that a future
 * convenience method could quietly break, and an exception that says so in the
 * stack trace is cheaper than discovering it in a payout dispute.
 *
 * EVERY REFUSAL CARRIES A MACHINE-READABLE `reason`, because the panel and the
 * storefront branch on it.
 *
 * @see docs/modules/Shipping.md §3, §7
 */
final class ShippingException extends BaseException
{
    protected int $status = Response::HTTP_UNPROCESSABLE_ENTITY;

    /**
     * No such shipment, or not this actor's.
     *
     * ONE ANSWER FOR BOTH — the rule every public surface here keeps.
     * Distinguishing them would let anyone check whether a shipment uuid is real
     * by watching which error comes back.
     */
    public static function notFound(string $shipmentUuid): self
    {
        return self::make(__('shipping.errors.not_found'))
            ->withContext(['reason' => 'shipment_not_found', 'shipment' => $shipmentUuid])
            ->withStatus(Response::HTTP_NOT_FOUND);
    }

    /**
     * The parcel is not in a state this operation accepts — usually already
     * shipped.
     *
     * A REFUSAL, NOT AN INCIDENT: a seller pressing "kargoya ver" twice is the
     * ordinary case, and the second press must change nothing rather than
     * overwrite the first tracking number with a second one.
     */
    public static function notAwaitingHandover(string $shipmentUuid, string $status): self
    {
        return self::make(__('shipping.errors.not_awaiting_handover'))
            ->withContext([
                'reason' => 'not_awaiting_handover',
                'shipment' => $shipmentUuid,
                'status' => $status,
            ])
            ->withStatus(Response::HTTP_CONFLICT);
    }

    /**
     * A carrier that does not exist, or one the operator has retired.
     */
    public static function carrierUnavailable(string $carrierUuid): self
    {
        return self::make(__('shipping.errors.carrier_unavailable'))
            ->withContext(['reason' => 'carrier_unavailable', 'carrier' => $carrierUuid]);
    }

    /**
     * Something tried to mark a shipment delivered on the seller's behalf.
     *
     * THE ONE RULE THAT KEEPS PAYOUT HONEST (ADR-064). It should be unreachable —
     * see the class docblock for why it exists anyway.
     */
    public static function sellerCannotDeliver(string $shipmentUuid): self
    {
        return self::make(__('shipping.errors.seller_cannot_deliver'))
            ->withContext(['reason' => 'seller_cannot_deliver', 'shipment' => $shipmentUuid])
            ->withStatus(Response::HTTP_FORBIDDEN);
    }
}
