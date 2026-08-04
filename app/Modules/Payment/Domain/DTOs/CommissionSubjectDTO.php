<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * What a commission rule is matched against — one order line's classification
 * (ADR-061, Payment.md §6).
 *
 * EVERY FIELD IS A SNAPSHOT taken at checkout, not a live read. A product
 * re-categorised or re-branded next month must not change which rule applied to a
 * sale already made, so the resolver is handed frozen values and has no way to ask
 * the catalogue anything.
 *
 * NULLS ARE REAL AND MEAN "this line has none". A product with no brand cannot
 * match a brand-scoped rule — it falls through to a less specific one, which is
 * the honest outcome rather than a guess.
 *
 * `categoryPathUuids` IS THE ANCESTRY, root first and including the category
 * itself, because a rule on a parent category covers its descendants. Membership
 * of this array IS the subtree test.
 *
 * `baseMinor` IS THE KDV-INCLUSIVE LINE TOTAL in kuruş — the gross the buyer paid
 * (owner choice, Payment.md §6), never the net of tax.
 */
final class CommissionSubjectDTO extends BaseDTO
{
    /**
     * @param array<int, string> $categoryPathUuids
     */
    public function __construct(
        public readonly string $sellerOrgUuid,
        public readonly int $baseMinor,
        public readonly ?string $productUuid = null,
        public readonly ?string $brandUuid = null,
        public readonly array $categoryPathUuids = [],
    ) {}
}
