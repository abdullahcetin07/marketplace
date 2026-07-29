<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A seller listing a catalog variant at a price (§3.4).
 *
 * CARRIES UUIDS FOR EVERYTHING FOREIGN, plus the one internal id the tenancy
 * filter needs (ADR-040). The variant is named by uuid because the Catalog owns
 * it and Offer may not import it; the org arrives as the id/uuid pair because
 * `organizationIdsForUser()` speaks internal ids while everything public speaks
 * uuid.
 *
 * `productUuid` is deliberately ABSENT: it is derived from the variant through
 * `CatalogQueryContract::productUuidForVariant()` at create time and denormalized
 * onto the row, so buy-box grouping never needs a per-read catalog call (§2.1).
 * Letting a caller pass it would be letting them pass a product the variant does
 * not belong to.
 *
 * Money arrives already in MINOR UNITS (non-negotiable #6). The conversion from
 * whatever a human typed happens at the Presentation boundary against the
 * `Currency` model, not here — a DTO that accepted "129,90" would be a second
 * place money parsing lives.
 *
 * DTOs are NOT validated (BaseDTO's rule); price > 0, list ≥ price, published
 * product and active store are the FormRequest's and the action's business.
 */
final class CreateOfferDTO extends BaseDTO
{
    public function __construct(
        public readonly string $variantUuid,
        public readonly int $sellingOrgId,
        public readonly string $sellingOrgUuid,
        public readonly string $storeUuid,
        public readonly int $priceMinor,
        public readonly int $stockQuantity,
        public readonly ?int $listPriceMinor = null,
        /**
         * Null means the platform default (₺). Stored per offer for a future
         * multi-currency marketplace, with no per-offer currency choice in the
         * seller UI this sprint (§13.1, confirmed by the owner).
         */
        public readonly ?int $currencyId = null,
    ) {}
}
