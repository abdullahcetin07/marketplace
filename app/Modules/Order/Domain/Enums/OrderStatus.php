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
 *   AwaitingPayment — placed, and the reservation is STILL HELD (ADR-057). The
 *                     units have not left the seller's shelf, because nothing is
 *                     paid for yet. The terminal state of this sprint.
 *   Cancelled       — the hold is released and nothing will be fulfilled.
 *                     Reachable from either state above.
 *
 * THREE CASES, AND THAT IS THE HONEST NUMBER. `Paid`, `Preparing`, `Shipped`,
 * `Delivered`, `Completed` and `Returned` are all real states this platform will
 * need — and every one of them belongs to a module that does not exist. Adding
 * them now would ship an enum whose cases nothing can ever set, and the first
 * reader would reasonably assume something does.
 *
 * WHY `AwaitingPayment` IS TERMINAL HERE. Payment is a later sprint (ADR-055), so
 * an order reaches the point of "we know what you owe" and stops. Nothing commits
 * this sprint at all (ADR-057): a successful charge is what will make the units
 * truly leave, and until Payment exists a placed order simply holds its
 * reservation. Payment adds its own case after this one and takes over the commit
 * — an additive change, which is the whole reason the two-step exists now.
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
    /**
     * Money arrived and the stock has left (Payment.md §5, added 2026-08-04).
     *
     * THE CASE ADR-057 PROMISED. Placement only HELD the reservation and this
     * enum said so; Payment is the module that commits it, and this is the state
     * on the other side of that commit.
     */
    case Paid = 'paid';

    /**
     * The money went back (Payment.md §8, added 2026-08-04 by P5).
     *
     * THE CASE THE `Paid` DOCBLOCK BELOW SAID BELONGED TO PAYMENT'S P5, added by
     * exactly that phase and by nothing earlier — the same discipline that kept
     * `Paid` itself out of this enum until Payment could set it.
     *
     * IT IS NOT `Cancelled`. A cancelled order gave a HOLD back; this one took
     * money, shipped nothing back into stock until now, and returned it. The two
     * look alike in a list and are entirely different in a ledger, which is where
     * an argument about an order is actually settled.
     *
     * **ADR-065 BLURRED THAT AND THEN SHARPENED IT SOMEWHERE ELSE.** A
     * pre-shipment cancellation also takes money back, so the distinction stopped
     * being "did money move". What separates them now is WHERE IN THE LIFECYCLE:
     * `Refunded` means goods reached the buyer and came back (or were paid for
     * and returned); `Cancelled` means they never left the seller. The ledger
     * reads the same; the parcel does not, and a future cancellation-rate penalty
     * would count one and not the other.
     */
    /**
     * The parcel reached the buyer (Shipping.md §4, added 2026-08-05 by S2).
     *
     * THE FULFILMENT STATE THIS ENUM KEPT REFUSING TO INVENT. `Preparing` and
     * `Shipped` are still absent and still belong to Shipping — where a parcel IS
     * is a shipment's business, and duplicating it here would create a second
     * source of truth for one fact. `Delivered` is different: it is the moment the
     * ORDER is complete from the customer's side, and it is what the return window
     * and the payout clock are measured from.
     *
     * ORDER DOES NOT DECIDE IT. Shipping infers delivery — the buyer confirms or
     * the transit window elapses (ADR-064) — and announces `ShipmentDelivered`;
     * this module subscribes by class-string and moves its own state, exactly as
     * it does for `PaymentSucceeded`.
     */
    case Delivered = 'delivered';

    case Refunded = 'refunded';

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
            // Cancellable after placement, and since ADR-057 the hold simply goes
            // back. It does NOT return to Pending: the customer has placed the
            // order, and rewinding to "still choosing" is not a state anyone asked
            // for.
            // Paid on a verified success callback; cancellable until then.
            self::AwaitingPayment => [self::Paid, self::Cancelled],
            /*
            | REFUNDABLE, AND NOTHING ELSE (P5, 2026-08-04). Fulfilment states
            | (Preparing/Shipped/Delivered) still belong to Shipping and are still
            | absent — this enum does not carry cases nothing can set.
            |
            | It is NOT cancellable BY THE PLAIN LEVER: the stock is committed
            | and the money is collected, so "cancel" now means REFUND, which is a
            | different operation with a different actor and a PSP call behind it.
            |
            | **`Cancelled` REJOINED THIS ROW IN ADR-065 (2026-08-06), AND THE
            | SENTENCE ABOVE IS STILL TRUE.** A paid order CAN end up cancelled —
            | the seller cannot fulfil it and the parcel has not left — but only
            | ever through the refund: the money goes back, the commission
            | reverses and the units are restocked before this transition
            | happens. The edge exists so the OUTCOME can be named honestly; it is
            | not permission to skip the refund.
            |
            | **AND THE TRANSITION TABLE IS NO LONGER THE ONLY GATE**, which is
            | the cost of adding it. `CancelOrderAction` — the plain lever that
            | releases a hold and zeroes a seller's stock — would have become
            | reachable on a paid order the moment this edge appeared, cancelling
            | a paid purchase with no money returned. It now refuses on
            | `isCancellableWithoutRefund()` instead of on this table alone.
            */
            self::Paid => [self::Delivered, self::Refunded, self::Cancelled],
            /*
            | A DELIVERED ORDER CAN STILL BE REFUNDED, and that edge is the whole
            | point of the return window: the buyer has the goods and a limited
            | time to send them back (S3/S4). It does NOT go back to `Paid` — a
            | parcel that arrived cannot un-arrive.
            */
            self::Delivered => [self::Refunded],
            // Terminal in both directions. Un-refunding would mean charging the
            // customer again, which is a new payment, not a state change.
            self::Refunded, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }

    /**
     * Whether this order is holding stock that a cancellation must give back.
     *
     * BOTH LIVE STATES DO, SINCE ADR-057, and that is the amendment in one method.
     * Placement used to COMMIT — the units left, and a later cancellation had
     * nothing to release, because Inventory has no un-commit. Now placement only
     * holds, so cancelling a placed order is the same plain `release` as
     * cancelling an un-placed one, and the stock comes back either way.
     *
     * When Payment ships and a successful charge commits, this narrows again — and
     * it narrows HERE, in one method, rather than at every call site that asks.
     */
    public function holdsReservation(): bool
    {
        /*
        | IT NARROWED HERE, exactly as this docblock predicted (2026-08-04).
        | `Paid` is deliberately absent: a paid order's reservation has been
        | COMMITTED, so the units are gone rather than held, and Inventory's
        | `release()` on a committed reference is a documented no-op. Treating a
        | paid order as holding stock would make a cancellation look like it gave
        | something back when it gave nothing.
        */
        return $this === self::Pending || $this === self::AwaitingPayment;
    }

    /**
     * Whether this order is still an UNPLACED checkout — the only thing the expiry
     * sweep may touch (§3.3, ADR-057).
     *
     * DISTINCT FROM `holdsReservation()` since ADR-057, and the distinction is the
     * whole of group A: both live states hold stock, but only one of them is an
     * abandoned tab. A placed order holds until it is paid or cancelled, however
     * long that takes — sweeping it would cancel a purchase the customer believes
     * they have made.
     */
    public function isAwaitingPlacement(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether the order is finished as far as stock is concerned — nothing more
     * to reserve, release or commit.
     */
    public function isTerminal(): bool
    {
        /*
        | `Paid` IS NO LONGER TERMINAL (P5, 2026-08-04) — a refund moves it, and it
        | moves the stock too: `RestockAction` puts the committed units back.
        | Shipping's S2 gave it a second exit (`Delivered`), exactly as that note
        | predicted. `Delivered` is not terminal either, for the same reason: the
        | return window is open behind it.
        */
        return $this === self::Cancelled || $this === self::Refunded;
    }

    /**
     * Whether the PLAIN cancel lever may touch this order (ADR-065).
     *
     * **THE GUARD THAT HAD TO EXIST THE MOMENT `Paid → Cancelled` DID.**
     * `CancelOrderAction` releases a hold and, for a seller, zeroes their
     * declared stock; on a paid order that would cancel a purchase with the money
     * still taken and the units still gone. It used to be impossible because the
     * transition table said so — and a table is exactly the wrong place to keep a
     * rule once a legitimate second path to the same state exists.
     *
     * A PAID ORDER IS CANCELLED THROUGH THE REFUND, or not at all
     * (`CancelOrderLinesAction`, Payment).
     */
    public function isCancellableWithoutRefund(): bool
    {
        return $this === self::Pending || $this === self::AwaitingPayment;
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
        /*
        | THIS IS THE NARROWING THE DOCBLOCK ABOVE PREDICTED (2026-08-04). Once
        | money has changed hands, walking away is a REFUND — a different
        | operation, with a PSP call behind it and an actor who may not be the
        | customer.
        |
        | IT NAMES THE TWO LIVE STATES RATHER THAN DELEGATING TO `isTerminal()`,
        | which it did until P5 made `Paid` non-terminal. Cancellation and stock
        | are two different questions, and one method answering both was only
        | correct while the answers happened to coincide.
        */
        return $this === self::Pending || $this === self::AwaitingPayment;
    }

    /**
     * Badge colour for the panels.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::AwaitingPayment => 'info',
            self::Paid => 'success',
            self::Delivered => 'success',
            self::Refunded => 'gray',
            self::Cancelled => 'danger',
        };
    }

    public function label(): string
    {
        return __("enums.OrderStatus.{$this->value}");
    }
}
