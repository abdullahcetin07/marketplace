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
 * complete and keeps the provider's own words in the log and the audit trail.
 *
 * THE INTERNAL MESSAGE IS A DIFFERENT STRING FROM THE USER-FACING ONE (2026-08-05).
 * `getMessage()` carries the provider's verbatim refusal, because that is what a
 * stack trace and a `report()` actually contain; `userMessage()` resolves a
 * translation from the `reason` in the context. Before that split, a live
 * get-token rejection produced a 422 and no record anywhere of why — the platform
 * took no money and nobody could say what PayTR had objected to.
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
     * A payout for nothing, or for a negative amount.
     *
     * Refused before anything is locked: a non-positive payout would append a
     * CREDIT and pay the seller by doing nothing.
     */
    public static function payoutAmountInvalid(int $amountMinor): self
    {
        return self::make(__('payment.errors.payout_amount_invalid'))
            ->withContext(['reason' => 'payout_amount_invalid', 'amount_minor' => $amountMinor]);
    }

    /**
     * The platform does not owe this seller that much.
     *
     * THE GUARD THAT KEEPS A BALANCE HONEST. A refund may drive a balance negative
     * afterwards (Payment.md §8) and that is allowed; paying out money the
     * platform does not owe is not — and since S3, neither is paying out money it
     * owes for a parcel the buyer can still return (ADR-064).
     */
    public static function payoutExceedsBalance(
        string $sellerOrgUuid,
        int $amountMinor,
        int $balanceMinor,
        int $onHoldMinor = 0,
    ): self {
        return self::make(__('payment.errors.payout_exceeds_balance'))
            ->withContext([
                'reason' => 'payout_exceeds_balance',
                'seller_org_uuid' => $sellerOrgUuid,
                'amount_minor' => $amountMinor,
                // What may actually be sent — not what is owed. @see
                // `SellerBalance`.
                'balance_minor' => $balanceMinor,
                // Owed, delivered too recently to draw. Reported so an admin
                // knows to wait rather than to open a ticket.
                'on_hold_minor' => $onHoldMinor,
            ]);
    }

    /**
     * The bank's answer is already recorded, and it is not re-recordable.
     *
     * A rejected transfer is retried by creating a NEW payout — which keeps the
     * failed attempt on the record for whoever reconciles the statement.
     */
    public static function payoutAlreadySettled(string $payoutUuid): self
    {
        return self::make(__('payment.errors.payout_already_settled'))
            ->withContext(['reason' => 'payout_already_settled', 'payout' => $payoutUuid])
            ->withStatus(Response::HTTP_CONFLICT);
    }

    /**
     * There is no money here to give back (P5).
     *
     * A payment that never collected — pending, failed, expired — has nothing to
     * reverse, and asking the PSP to refund one would be asking it about a
     * transaction it does not have.
     */
    public static function notRefundable(string $paymentUuid, string $status): self
    {
        return self::make(__('payment.errors.not_refundable'))
            ->withContext(['reason' => 'not_refundable', 'payment' => $paymentUuid, 'status' => $status])
            ->withStatus(Response::HTTP_CONFLICT);
    }

    /**
     * Every order named is already refunded, or none of them belongs to this
     * payment (P5).
     *
     * THE SECOND-CLICK ANSWER. A refund is the one operation in this module a
     * human triggers by clicking, so it will be clicked twice; the first click
     * refunded, and this is what the second one gets. A refusal, not an incident.
     */
    public static function nothingToRefund(string $paymentUuid): self
    {
        return self::make(__('payment.errors.nothing_to_refund'))
            ->withContext(['reason' => 'nothing_to_refund', 'payment' => $paymentUuid])
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
        $exception = self::make("PayTR could not be reached: {$detail}")
            ->withContext(['reason' => 'gateway_unavailable', 'detail' => $detail])
            ->withStatus(Response::HTTP_SERVICE_UNAVAILABLE);

        $exception->reportable = true;

        return $exception;
    }

    /**
     * The PSP was reached and refused the request — a bad merchant configuration,
     * a rejected basket, an amount it will not take.
     *
     * NOT reportable: it is an answer, not an outage.
     *
     * THE PROVIDER'S OWN WORDS ARE THE EXCEPTION MESSAGE (2026-08-05), because
     * that message is what a stack trace and a `report()` actually carry, and
     * without it a live rejection was undiagnosable — the platform took no money
     * and nothing recorded why. PayTR's `reason` is written for an OPERATOR
     * ("MAĞAZA PARAMETRELERINI KONTROL EDINIZ"), not for a shopper.
     *
     * The BUYER still never sees it: `userMessage()` below resolves the
     * translation from the context `reason` rather than falling back to this
     * string.
     */
    public static function gatewayRejected(string $detail): self
    {
        return self::make("PayTR refused the request: {$detail}")
            ->withContext(['reason' => 'gateway_rejected', 'detail' => $detail]);
    }

    /**
     * What the buyer reads — never what the PSP said.
     *
     * WHY THIS IS OVERRIDDEN HERE. `BaseException::userMessage()` looks up
     * `errors.{snake class name}`, which is `errors.payment_exception` for EVERY
     * failure in this class — one key for nine different refusals — so it always
     * fell through to `getMessage()`. That was harmless while the message and the
     * buyer-facing text were the same string, and stopped being harmless the
     * moment the message started carrying PayTR's own words.
     *
     * So the lookup is keyed on the `reason` every factory here already sets,
     * which is the same value a client branches on. The result: one exception
     * carries an operator's diagnosis in `getMessage()` and a shopper's sentence
     * in `userMessage()`, and neither leaks into the other.
     */
    public function userMessage(): string
    {
        $reason = $this->getContext()['reason'] ?? null;

        if (is_string($reason) && $reason !== '') {
            $key = "payment.errors.{$reason}";
            $translated = __($key);

            if (is_string($translated) && $translated !== $key) {
                return $translated;
            }
        }

        return parent::userMessage();
    }
}
