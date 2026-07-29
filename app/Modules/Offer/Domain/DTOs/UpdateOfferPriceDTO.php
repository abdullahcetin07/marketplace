<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A re-price (§3.1) — the most frequent write this module takes.
 *
 * PRICE AND STOCK ARE SEPARATE DTOs, and separate actions, because they are
 * separate facts with separate events: `OfferPriceChanged` moves the buy box and
 * is what a price-history or price-drop-alert feature reads, while
 * `OfferStockChanged` only flips sellability. Folding them into one "update
 * offer" payload would make every restock look like a re-price to every
 * downstream consumer.
 *
 * `listPriceMinor` uses PATCH semantics: `present` distinguishes "not supplied"
 * from "supplied as null", because clearing the struck-through price is a real
 * thing a seller does when a campaign ends, and it must not be indistinguishable
 * from leaving it alone. @see AdminUpdateUserDTO for the same pattern.
 */
final class UpdateOfferPriceDTO extends BaseDTO
{
    public function __construct(
        public readonly int $priceMinor,
        public readonly ?int $listPriceMinor = null,
        /** @var array<int, string> */
        public readonly array $present = [],
        /** Why — recorded in the audit trail alongside the before/after. */
        public readonly ?string $reason = null,
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }
}
