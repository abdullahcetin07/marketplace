<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * The seller declined the return (ADR-073).
 *
 * **THE REASON TRAVELS WITH IT**, because a refusal without a word is the support
 * ticket the column exists to prevent, and a future notification is exactly the
 * surface that would otherwise send one.
 *
 * IT DOES NOT MEAN THE ORDER IS SETTLED. The window may still be open, the buyer
 * may ask again, and an admin refund remains — a seller's "no" is an answer, not
 * an expiry.
 */
final class ReturnRejected extends BaseEvent
{
    public function __construct(
        public readonly string $returnRequestUuid,
        public readonly string $orderUuid,
        public readonly ?string $reason = null,
    ) {
        parent::__construct();
    }
}
