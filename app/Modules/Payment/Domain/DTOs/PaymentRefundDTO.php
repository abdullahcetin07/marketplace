<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A request to give money back (P5).
 *
 * PARTIAL IS THE DEFAULT SHAPE, not a special case: `amountMinor` is always
 * stated, and a full refund is simply the whole amount. A nullable "refund
 * everything" would make the common case ambiguous the day a second partial
 * refund follows the first.
 */
final class PaymentRefundDTO extends BaseDTO
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amountMinor,
    ) {}
}
