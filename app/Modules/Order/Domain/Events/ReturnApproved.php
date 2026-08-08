<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * The seller agreed to take it back, and said how (ADR-073).
 *
 * **IT CARRIES THE RETURN CODE, which is the one field a listener would need.**
 * The obvious consumer is the "iade kodunuz: XXX" message to the buyer; none
 * ships in v1, so the storefront reads it from the request instead.
 *
 * STILL NO MONEY. Approval is an instruction, not a payment — @see
 * `App\Modules\Order\Application\Actions\ApproveReturnAction`.
 */
final class ReturnApproved extends BaseEvent
{
    public function __construct(
        public readonly string $returnRequestUuid,
        public readonly string $orderUuid,
        public readonly string $returnCode,
    ) {
        parent::__construct();
    }
}
