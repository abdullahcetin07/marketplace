<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * What an actor asked to refund (P5, Payment.md §8).
 *
 * ORDERS, NOT AN AMOUNT, and that is the decision this DTO encodes. A refund on
 * this platform gives back one seller's ORDER — because that is the unit the
 * buyer recognises ("this parcel"), the unit the seller's ledger is keyed on, and
 * the unit whose stock can be put back. An arbitrary lira figure could be none of
 * those: it could not say which seller it came out of, which commission to give
 * back, or which units to restock.
 *
 * So `partially_refunded` on this platform means "some of the sellers' orders in
 * this basket", not "some of the money".
 *
 * AN EMPTY LIST MEANS THE WHOLE PAYMENT — the common case, spelled as the absence
 * of a choice rather than as a flag the caller could forget to set.
 *
 * @see App\Modules\Payment\Application\Actions\RefundPaymentAction
 */
final class RefundRequestDTO extends BaseDTO
{
    /**
     * @param array<int, string> $orderUuids empty = every order in the payment
     */
    public function __construct(
        public readonly string $paymentUuid,
        public readonly array $orderUuids = [],
        public readonly ?string $reason = null,
        public readonly ?int $actorId = null,
    ) {}
}
