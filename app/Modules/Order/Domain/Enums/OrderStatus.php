<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * The lifecycle of one seller's order, as far as THIS sprint takes it
 * (Order.md §2.5).
 *
 *   Pending         — created by checkout, stock RESERVED, not yet placed. The
 *                     window in which a customer is choosing an address or
 *                     reaching for a card, and the only state the expiry sweep
 *                     touches.
 *   AwaitingPayment — placed; the reservation is COMMITTED and the units have
 *                     left the seller's shelf (ADR-054). The terminal state of
 *                     this sprint.
 *   Cancelled       — the hold or the committed stock is released and nothing
 *                     will be fulfilled. Reachable from either state above.
 *
 * THREE CASES, AND THAT IS THE HONEST NUMBER. `Paid`, `Preparing`, `Shipped`,
 * `Delivered`, `Completed` and `Returned` are all real states this platform will
 * need — and every one of them belongs to a module that does not exist. Adding
 * them now would ship an enum whose cases nothing can ever set, and the first
 * reader would reasonably assume something does.
 *
 * WHY `AwaitingPayment` IS TERMINAL HERE. Payment is a later sprint (ADR-055), so
 * an order reaches the point of "we know what you owe" and stops. When Payment
 * ships it adds its own case after this one and moves the COMMIT to
 * payment-success; placement then only holds, which is the reservation window
 * Inventory was built for (ADR-049/054). That is an additive change to this enum,
 * not a reshaping of the flow — the whole reason the two-step exists now.
 *
 * OUT-OF-STOCK IS NOT A STATE, and neither is "reserved": what stock an order is
 * holding is Inventory's to answer, through the reservation keyed on the order
 * uuid. A status column that also tried to say it would be a second source of
 * truth for one fact — the `OfferStatus` reasoning about out-of-stock, applied
 * one module along.
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 *
 * @see docs/modules/Order.md §2.5, §3.3
 */
enum OrderStatus: string
{
    use HasEnumHelpers;

    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case Cancelled = 'cancelled';

    /**
     * The states an order may still move to.
     *
     * `Cancelled` is terminal in both directions: a cancelled order released its
     * stock, and "un-cancelling" would have to re-reserve units somebody else may
     * already have bought. A customer who changes their mind checks out again.
     *
     * @return array<int, self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::AwaitingPayment, self::Cancelled],
            // Cancellable after placement — the committed stock goes back. It
            // does NOT return to Pending: the reservation is gone, and there is
            // nothing to un-commit into.
            self::AwaitingPayment => [self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }

    /**
     * Whether this order still holds a reservation rather than committed stock.
     *
     * The distinction the whole two-step turns on (ADR-054): a `Pending` order's
     * units are HELD and go back on release; an `AwaitingPayment` order's units
     * have LEFT, and cancelling one means putting them back on the shelf. Both
     * are `release`/`commit` on the same reference, so the caller has to know
     * which it is holding.
     */
    public function holdsReservation(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether the order is finished as far as stock is concerned — nothing more
     * to reserve, release or commit.
     */
    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }

    /**
     * Whether a customer may still walk away from it.
     *
     * Both live states, this sprint, and deliberately: nothing has been charged
     * and nothing has shipped, so there is no cost to the seller in letting them.
     * When Payment and Shipping exist, this is the method that narrows — a
     * dispatched order is not the customer's to cancel — which is why the
     * question is asked here rather than re-derived at each call site.
     */
    public function isCancellableByCustomer(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Badge colour for the panels.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::AwaitingPayment => 'info',
            self::Cancelled => 'danger',
        };
    }

    public function label(): string
    {
        return __("enums.OrderStatus.{$this->value}");
    }
}
