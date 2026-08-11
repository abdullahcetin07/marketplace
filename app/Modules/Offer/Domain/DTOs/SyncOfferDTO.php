<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\DTOs;

/**
 * One item of a seller's offer feed (ADR-076).
 *
 * **THE SELLER IS ON THE DTO, NOT IN THE ITEM.** `sellingOrgId`, `sellingOrgUuid`
 * and `storeUuid` are resolved by the adapter from the AUTHENTICATED actor — never
 * read off the wire — so a token cannot write another merchant's offers by naming
 * them. The seller panel resolves the same three the same way; the feed is a
 * second door onto one rule, not a second rule.
 *
 * **MONEY ARRIVES HERE ALREADY IN MINOR UNITS.** The wire carries a decimal string
 * ("129.90") and the request boundary converts it (ADR-005); by the time it is a
 * DTO it is an integer of kuruş, because a float that got this far would be a
 * float in the database.
 *
 * `stockQuantity` IS ABSOLUTE, never a delta (spec §1): a feed that said "+3" would
 * be unsafe to retry, and every one of these calls has to be safe to retry.
 *
 * @see App\Modules\Offer\Application\Actions\SyncSellerOfferAction
 */
final class SyncOfferDTO
{
    public function __construct(
        public readonly int $sellingOrgId,
        public readonly string $sellingOrgUuid,
        public readonly string $storeUuid,
        public readonly string $gtin,
        public readonly ?int $priceMinor = null,
        public readonly ?int $stockQuantity = null,
        public readonly ?int $listPriceMinor = null,
    ) {}
}
