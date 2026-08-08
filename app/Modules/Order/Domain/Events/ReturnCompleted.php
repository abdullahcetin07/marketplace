<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * The goods are back and the refund has fired (ADR-073).
 *
 * **IT IS NOT THE REFUND, AND NOTHING SHOULD ACT ON IT AS THOUGH IT WERE.** By
 * the time this fires, `RefundLinesAction` has already committed, `PaymentRefunded`
 * has already announced the money, and Order and Shipping have already moved the
 * order and the parcel from that cause. This says only that the REQUEST reached
 * its end — the workflow's closing note, not the transaction's.
 *
 * A listener that wanted to act on the money must subscribe to `PaymentRefunded`
 * instead; one that wants to thank the buyer for completing a return belongs here.
 */
final class ReturnCompleted extends BaseEvent
{
    public function __construct(
        public readonly string $returnRequestUuid,
        public readonly string $orderUuid,
    ) {
        parent::__construct();
    }
}
