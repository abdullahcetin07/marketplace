<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * What one order line's commission came to (ADR-061, Payment.md §6).
 *
 * BOTH THE RATE AND THE KURUŞ, because both are frozen onto the line. The amount
 * alone would leave "15% of what?" unanswerable a year later, and the rate alone
 * would have to be recomputed against a base that may since have been refunded in
 * part.
 *
 * `ruleUuid` IS CARRIED FOR THE AUDIT TRAIL — which rule won, so "why 12%?" has an
 * answer that points at a row rather than at an algorithm. Null when the platform
 * has configured no rules at all, in which case the rate is zero and the platform
 * takes nothing.
 *
 * `rate` IS A DECIMAL STRING and `amountMinor` IS AN INTEGER: the money rule in
 * one class (ADR-005). A percentage is a ratio; the lira it produces are kuruş.
 */
final class CommissionDTO extends BaseDTO
{
    public function __construct(
        public readonly ?string $ruleUuid,
        public readonly string $rate,
        public readonly int $amountMinor,
    ) {}
}
