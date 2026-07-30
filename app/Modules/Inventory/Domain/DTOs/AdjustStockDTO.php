<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * The seller's declared on-hand for one (org, variant), mirrored from the Offer
 * (§3.1, ADR-048).
 *
 * AN ABSOLUTE QUANTITY, NOT A DELTA — because the Offer form is absolute ("elimde
 * 12 adet var") and the mirror must say the same thing the seller typed. The
 * action computes the delta against the current projection and records THAT in
 * the ledger, so a replayed or out-of-order event converges on the seller's
 * number rather than compounding.
 *
 * EVERY FOREIGN REFERENCE IS A UUID, plus the org's internal id, which the
 * tenancy filter needs (ADR-040). They arrive from an Offer event this module
 * consumes blind — it never imports Offer to look anything up.
 */
final class AdjustStockDTO extends BaseDTO
{
    public function __construct(
        public readonly string $variantUuid,
        public readonly string $productUuid,
        public readonly int $sellingOrgId,
        public readonly string $sellingOrgUuid,
        /** The seller's new declared on-hand. Never negative. */
        public readonly int $onHand,
        /** The offer this stock belongs to, for provenance. */
        public readonly ?string $offerUuid = null,
        /** Why — recorded on the movement, read in the seller's history. */
        public readonly ?string $note = null,
    ) {}
}
