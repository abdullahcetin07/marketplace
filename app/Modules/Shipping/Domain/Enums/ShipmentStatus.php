<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Where one parcel is (ADR-063, Shipping.md §2).
 *
 *   Pending   — the order is paid and the seller has not handed it over yet.
 *   Shipped   — it is with the carrier; `shipped_at`, tracking number, carrier.
 *   Delivered — it arrived. `delivered_at` is what payout and the return window
 *               key off (ADR-064).
 *   Returned  — it came back, driven by Payment's refund (S4), never by Shipping.
 *
 * **THERE IS NO CASE THE SELLER CAN SET FOR `Delivered`**, and that is the one
 * rule that keeps payout honest (ADR-064): the seller is paid on delivery, so a
 * seller who could assert it would be asserting their own payday. Delivery is
 * inferred — the buyer confirms, or the transit window elapses — which is S2's
 * work; this enum only refuses to pretend otherwise.
 *
 * `Returned` EXISTS BUT NOTHING SETS IT IN S1, which is the exception this file
 * makes to the platform's usual "no case nothing can reach" discipline
 * (`OrderStatus` withheld `Paid` until Payment could set it). The reason is
 * different here: it is reachable within THIS module's own phases from a state S1
 * already writes, and the transition table is what the S1 tests assert against.
 * The same argument `PaymentStatus` made for its refund cases.
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 *
 * @see docs/modules/Shipping.md §2
 */
enum ShipmentStatus: string
{
    use HasEnumHelpers;

    case Pending = 'pending';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Returned = 'returned';

    /**
     * The states this one may still move to.
     *
     * @return array<int, self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Shipped],
            // Delivery is INFERRED (S2), never asserted — see the class docblock.
            self::Shipped => [self::Delivered],
            // A return is Payment's refund reaching back here (S4), not a lever in
            // this module.
            self::Delivered => [self::Returned],
            // Terminal: a returned parcel that ships again is a new order.
            self::Returned => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }

    /**
     * Whether the parcel is still on its way — the question the transit sweep
     * (S2) asks, and the one the storefront's "Teslim aldım" button asks.
     */
    public function isInTransit(): bool
    {
        return $this === self::Shipped;
    }

    /**
     * Whether the seller may still hand this parcel over.
     *
     * Asked here rather than re-derived at each call site, because the panel, the
     * action and the policy must all agree about it.
     */
    public function isAwaitingHandover(): bool
    {
        return $this === self::Pending;
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Shipped => 'info',
            self::Delivered => 'success',
            self::Returned => 'danger',
        };
    }

    public function label(): string
    {
        return __("enums.ShipmentStatus.{$this->value}");
    }
}
