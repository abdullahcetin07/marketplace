<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A seller opening a product proposal — "ürün aç" (§5, entry point 2).
 *
 * `proposedByOrgId`/`proposedByOrgUuid` are PROVENANCE, not ownership
 * (ADR-037/040): they scope who may edit the draft, and stop mattering once the
 * product is published into the shared catalog. Both null when staff author a
 * product directly. The pair travels together — the id is what the seller panel
 * filters on, the uuid is what leaves the application (ADR-033).
 *
 * NO PRICE AND NO STOCK FIELDS, here or anywhere in this module (ADR-037). If a
 * form needs them it is an Offer form, not this one.
 *
 * `gtin` is the shared catalog's dedup key (§3.4) — supplying it is what lets
 * the platform tell a seller "this product already exists, offer it instead of
 * re-creating it".
 */
final class DraftProductDTO extends BaseDTO
{
    /**
     * @param array<string, string|null> $title
     * @param array<string, string|null> $description
     */
    public function __construct(
        public readonly string $categoryUuid,
        public readonly array $title,
        public readonly array $description = [],
        public readonly ?string $brandUuid = null,
        public readonly ?string $gtin = null,
        public readonly ?string $slug = null,
        public readonly ?int $proposedByOrgId = null,
        public readonly ?string $proposedByOrgUuid = null,
        public readonly ?int $proposedByUserId = null,
    ) {}
}
