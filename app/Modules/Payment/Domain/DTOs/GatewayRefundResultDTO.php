<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * The PSP's answer to a refund request (P5).
 */
final class GatewayRefundResultDTO extends BaseDTO
{
    public function __construct(
        public readonly bool $successful,
        public readonly int $amountMinor = 0,
        public readonly ?string $failureReason = null,
        public readonly ?string $providerReference = null,
    ) {}
}
