<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A restock or a stock correction (§3.3).
 *
 * AN ABSOLUTE QUANTITY, NOT A DELTA. The seller says "elimde 12 adet var", not
 * "+3": a delta needs a known starting point, and this sprint has no reservation
 * semantics to guarantee one (ADR-043). When Inventory ships and becomes the
 * authority, deltas against reservations become meaningful and this DTO is what
 * gets migrated — deliberately, not accidentally.
 *
 * Zero is legitimate and is how a seller says "tükendi": it derives out-of-stock
 * (`stock_quantity = 0`) without touching status, so the offer keeps its price
 * and its place and simply stops winning the buy box.
 */
final class UpdateOfferStockDTO extends BaseDTO
{
    public function __construct(
        public readonly int $stockQuantity,
        /** Why — recorded in the audit trail alongside the before/after. */
        public readonly ?string $reason = null,
    ) {}
}
