<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * Everything the PSP needs to open a payment session (Payment.md §3).
 *
 * `amountMinor` IS KURUŞ, like every other amount on this platform (ADR-005) —
 * and, conveniently, like PayTR's own unit, so nothing is converted anywhere. The
 * moment a float appears in this chain it is a financial bug, not a rounding
 * curiosity, so there is no decimal anywhere in the DTO.
 *
 * `reference` IS OUR IDEMPOTENCY KEY — the Payment uuid, which PayTR calls
 * `merchant_oid` and echoes back on every callback including its retries. It is
 * what lets the callback recognise a payment it has already processed.
 *
 * THE BASKET IS DESCRIPTIVE, NOT AUTHORITATIVE. PayTR shows it to the buyer on
 * the payment page; the amount charged is `amountMinor`, and the two are computed
 * from the same orders rather than one from the other.
 */
final class PaymentIntentDTO extends BaseDTO
{
    /**
     * @param array<int, array{name: string, price: string, quantity: int}> $basket
     */
    public function __construct(
        public readonly string $reference,
        public readonly int $amountMinor,
        public readonly string $currencyCode,
        public readonly string $buyerEmail,
        public readonly string $buyerName,
        public readonly string $buyerAddress,
        public readonly string $buyerPhone,
        public readonly string $buyerIp,
        public readonly array $basket = [],
    ) {}
}
