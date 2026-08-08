<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A buyer asked to send something back (ADR-073).
 *
 * **A HOOK WITH NO LISTENER, DELIBERATELY.** The obvious consumer is a "yeni iade
 * talebi" notice to the seller — the panel's queue badge is what v1 has instead —
 * and it is not built. The event fires now so that notice is a new class rather
 * than a change to this module.
 *
 * **NO MONEY IN THE PAYLOAD, BECAUSE NO MONEY HAS MOVED.** That is the whole
 * distinction ADR-073 draws: this announces a REQUEST. `PaymentRefunded` is what
 * announces a refund, and it fires at `ReturnCompleted` time, from Payment.
 */
final class ReturnRequested extends BaseEvent
{
    public function __construct(
        public readonly string $returnRequestUuid,
        public readonly string $orderUuid,
        public readonly int $customerId,
    ) {
        parent::__construct();
    }
}
