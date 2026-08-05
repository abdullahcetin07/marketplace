<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * HOW a shipment came to be considered delivered (ADR-064).
 *
 * **THE COLUMN THAT SAYS HOW MUCH THE DELIVERY DATE IS WORTH.** Payout and the
 * return window both key off `delivered_at` (Shipping.md §4), and in v1 that
 * timestamp is often INFERRED rather than observed — so a dispute six weeks later
 * turns on which of these set it:
 *
 *   Buyer        — the customer pressed "Teslim aldım". The strongest signal the
 *                  platform has, because the person who received the parcel said so.
 *   TransitSweep — nobody said anything and `shipped_at + transit_days` elapsed.
 *                  A heuristic, and the one an unhappy buyer will contest.
 *   Carrier      — a real carrier integration reported it. Nothing sets this in
 *                  v1; the case exists because ADR-064 states that when the
 *                  integration lands its event REPLACES the heuristic, and the
 *                  downstream must be able to tell the two apart from day one.
 *
 * `Carrier` is therefore a deliberate exception to "no case nothing can set": it
 * is the field's whole point — recording the provenance of a date — and a
 * provenance vocabulary that omits the trustworthy source would have to be
 * corrected rather than extended.
 *
 * **THE SELLER IS ABSENT, AND THAT IS THE POINT.** There is no case for "the
 * seller said so", because the seller is paid on delivery (ADR-064).
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 *
 * @see docs/modules/Shipping.md §3
 */
enum DeliveredVia: string
{
    use HasEnumHelpers;

    case Buyer = 'buyer';
    case TransitSweep = 'transit_sweep';
    case Carrier = 'carrier';

    /**
     * Whether a human actually told us, as opposed to a clock deciding.
     *
     * The question a support agent arbitrating "I never got it" is really asking.
     */
    public function isObserved(): bool
    {
        return $this !== self::TransitSweep;
    }

    public function color(): string
    {
        return match ($this) {
            self::Buyer => 'success',
            self::TransitSweep => 'warning',
            self::Carrier => 'info',
        };
    }

    public function label(): string
    {
        return __("enums.DeliveredVia.{$this->value}");
    }
}
