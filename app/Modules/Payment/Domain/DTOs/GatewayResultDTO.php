<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A callback, after its hash has been checked (Payment.md §3).
 *
 * `verified` IS SEPARATE FROM `successful`, and conflating them would be the
 * security bug this whole endpoint exists to avoid. A forged POST claiming
 * success arrives as `verified: false, successful: true` — the caller must look
 * at the first, and a single "is it ok" boolean would invite looking at the
 * second.
 *
 * `amountMinor` COMES BACK TOO so the caller can check the PSP charged what was
 * asked for. A callback that verifies but names a different amount is not a
 * payment for this order.
 */
final class GatewayResultDTO extends BaseDTO
{
    public function __construct(
        /** Did the hash check out? Nothing else here means anything if this is false. */
        public readonly bool $verified,
        public readonly bool $successful,
        /** Our `merchant_oid` — the Payment uuid. */
        public readonly string $reference,
        public readonly int $amountMinor = 0,
        /** The PSP's failure reason, verbatim, for support and the audit trail. */
        public readonly ?string $failureReason = null,
        public readonly ?string $providerReference = null,
    ) {}
}
