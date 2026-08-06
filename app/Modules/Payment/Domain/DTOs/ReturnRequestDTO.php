<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;
use App\Modules\Payment\Domain\Enums\RefundCause;

/**
 * "Bunu geri göndereceğim" — which items, and how many of each (S4).
 *
 * LINES AND QUANTITIES, NOT AN AMOUNT. P5 established that a refund names ORDERS
 * rather than money; S4 sharpens the same rule one level down. A buyer knows what
 * they are sending back — one of the two shoes — and the platform prices it. A
 * lira figure from the client would be a client deciding what its own return is
 * worth.
 *
 * KEYED ON THE ORDER, because that is what the customer holds and what the return
 * window is measured against. The line uuids belong to it, and a line naming
 * another order is simply absent from the result.
 */
final class ReturnRequestDTO extends BaseDTO
{
    /**
     * @param array<string, int> $quantities order line uuid => how many come back
     */
    public function __construct(
        public readonly string $orderUuid,
        public readonly array $quantities,
        public readonly ?string $reason = null,
        public readonly ?int $actorId = null,
        /**
         * Why these units are going back (ADR-065, C1).
         *
         * THE ONE FIELD A CANCELLATION ADDS. Everything else about undoing a sale
         * is identical — the amounts, the commission share, the restock, the two
         * ledger entries — so a cancellation is this DTO with a different cause
         * rather than a second refund implementation. @see `RefundCause`.
         */
        public readonly RefundCause $cause = RefundCause::Return,
    ) {}
}
