<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use Illuminate\Http\Response;

/**
 * A payment operation the domain or the PSP refuses.
 *
 * MOSTLY EXPECTED REFUSALS, like every other module's — a group that is already
 * paid, a group with nothing to charge for. `$reportable` stays false by default:
 * a buyer double-clicking "öde" is the system working.
 *
 * WITH ONE DELIBERATE EXCEPTION: `gatewayUnavailable()` IS reportable. Every other
 * failure on this platform is somebody doing something the rules forbid; that one
 * is the payment provider being unreachable, which means the platform is taking no
 * money at all and nobody would otherwise find out until a seller asked why sales
 * stopped. It is the rare case where waking somebody is the correct behaviour.
 *
 * EVERY REFUSAL CARRIES A MACHINE-READABLE `reason`, because the storefront reacts
 * differently to each: "already paid" means redirect to the receipt, "unavailable"
 * means offer a retry, "nothing to pay" means the basket went stale.
 *
 * NOTHING HERE EVER CARRIES PSP DETAIL INTO A USER-FACING MESSAGE. A declined card
 * is the buyer's business with their bank; the platform says the payment did not
 * complete and keeps the provider's own words in the audit trail.
 *
 * @see docs/modules/Payment.md §3, §9
 */
final class PaymentException extends BaseException
{
    protected int $status = Response::HTTP_UNPROCESSABLE_ENTITY;

    /**
     * The checkout group does not exist, or does not belong to this customer.
     *
     * ONE ANSWER FOR BOTH, which is the same rule every public surface on this
     * platform keeps: distinguishing them would let anyone check whether a given
     * group uuid is real by watching which error comes back.
     */
    public static function groupNotFound(string $checkoutGroupUuid): self
    {
        return self::make(__('payment.errors.group_not_found'))
            ->withContext(['reason' => 'group_not_found', 'checkout_group' => $checkoutGroupUuid])
            ->withStatus(Response::HTTP_NOT_FOUND);
    }

    /**
     * The group resolves but has no payable total — every order in it was
     * cancelled, or it never had one.
     */
    public static function nothingToPay(string $checkoutGroupUuid): self
    {
        return self::make(__('payment.errors.nothing_to_pay'))
            ->withContext(['reason' => 'nothing_to_pay', 'checkout_group' => $checkoutGroupUuid]);
    }

    /**
     * Money has already been collected for this basket.
     *
     * The guard that stops a second charge for one purchase — the worst thing this
     * module could do — reaching the PSP at all.
     */
    public static function alreadySettled(string $checkoutGroupUuid): self
    {
        return self::make(__('payment.errors.already_settled'))
            ->withContext(['reason' => 'already_settled', 'checkout_group' => $checkoutGroupUuid])
            ->withStatus(Response::HTTP_CONFLICT);
    }

    /**
     * The PSP could not be reached, or answered something unusable.
     *
     * REPORTABLE, unlike everything else here — see the class docblock. The
     * platform is taking no money while this is true.
     */
    public static function gatewayUnavailable(string $detail): self
    {
        $exception = self::make(__('payment.errors.gateway_unavailable'))
            ->withContext(['reason' => 'gateway_unavailable', 'detail' => $detail])
            ->withStatus(Response::HTTP_SERVICE_UNAVAILABLE);

        $exception->reportable = true;

        return $exception;
    }

    /**
     * The PSP was reached and refused the request — a bad merchant configuration,
     * a rejected basket, an amount it will not take.
     *
     * NOT reportable: it is an answer, not an outage. The provider's own words go
     * into the context for support, never into the buyer's message.
     */
    public static function gatewayRejected(string $detail): self
    {
        return self::make(__('payment.errors.gateway_rejected'))
            ->withContext(['reason' => 'gateway_rejected', 'detail' => $detail]);
    }
}
