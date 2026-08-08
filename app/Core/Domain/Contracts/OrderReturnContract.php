<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * The command port a seller's panel drives to refund a return they have RECEIVED
 * (ADR-073).
 *
 * **THE PLATFORM'S THIRD COMMAND PORT, AND THE SECOND OF ITS EXACT SHAPE.** C1's
 * `OrderCancellationContract` established the pattern and this repeats it
 * knowingly: the seller acts from a screen Order owns, the refund belongs to
 * Payment, neither module may import the other, and an event will not do because
 * the seller pressing "İadeyi tamamla" must be told *in that request* that the
 * window closed or that a line was already refunded.
 *
 * **IT IS NOT `OrderCancellationContract` WITH A DIFFERENT NAME**, and the two
 * places it differs are the two places it could not be reused:
 *
 *   - **C1 refuses a shipped parcel** (`assertAwaitingHandover`), which is
 *     precisely the state a return begins in. Its gate is "the goods never left";
 *     this one's is "the goods arrived and are coming back".
 *   - **C1 hard-codes `cause: Cancellation`**, and the cause is what decides
 *     whether the order ends `cancelled` or `refunded` and the parcel `cancelled`
 *     or `returned` (ADR-065). Borrowing it would tell every buyer their delivered
 *     parcel was cancelled.
 *
 * The machinery underneath is identical — `RefundLinesAction`, unchanged. Only the
 * gate and the cause differ, which is exactly why they are two ports and one
 * action rather than one port with a flag.
 *
 * **THE REFUND FIRES HERE, NOT WHEN THE BUYER ASKS.** That is the whole of
 * ADR-073: ADR-064 treated the return window as the approval and paid out on
 * request, which for physical goods means refunding on trust. The seller
 * confirming they have the parcel back is the trigger, and this is the wire it
 * travels on.
 *
 * MONEY NEVER CROSSES IT. A return names lines and quantities; the platform prices
 * them from the frozen snapshot (Payment.md §8).
 *
 * @see App\Modules\Payment\Infrastructure\Commands\OrderReturn
 * @see docs/modules/Payment.md §8
 */
interface OrderReturnContract
{
    /**
     * Whether this order was delivered and is still inside its return window.
     *
     * **FALSE FOR "NEVER DELIVERED" AND "TOO LATE" ALIKE**, and a caller must not
     * try to tell them apart through this port: both mean "no return from here",
     * and the difference is the seller's business rather than the buyer's to
     * probe. Order asks it before writing a request at all, so a buyer whose
     * window has closed is refused at the door instead of days later.
     */
    public function isReturnOpen(string $orderUuid): bool;

    /**
     * How many units of each line may still be returned — line uuid => count,
     * omitting the lines with nothing left.
     *
     * **THE SAME READ-ON-A-COMMAND-PORT C1 JUSTIFIES**, for the same reason: the
     * cap is a subtraction only Payment can make, because the order knows what was
     * bought and Payment knows what has already gone back. It is a HINT — the
     * request form's per-line maximum, and the check Order runs before accepting
     * a buyer's ask — never the guard. `RefundableLines` re-checks every quantity
     * behind the port when the money actually moves, which matters more here than
     * for a cancellation: a return request can sit for DAYS between being written
     * and being completed, and an admin may have refunded one of its lines
     * meanwhile.
     *
     * Empty when the order has no payment or nothing is returnable, so a caller
     * that renders nothing renders the right thing.
     *
     * @return array<string, int>
     */
    public function returnableQuantities(string $orderUuid): array;

    /**
     * The goods are back: refund `$quantities` units of this order's lines,
     * reversing the seller's commission and restocking them.
     *
     * Throws when the order is not the seller's, when the return window is closed,
     * when nothing may be returned, or when the PSP refuses. **A caller must
     * surface the failure and must not stamp its request `Completed`** — the money
     * is what the buyer is waiting for, and a completed return beside a refund
     * that never happened is a support ticket nobody can reconstruct.
     *
     * @param array<string, int> $quantities order line uuid => how many to refund
     */
    public function completeReturnBySeller(
        string $orderUuid,
        string $sellerOrgUuid,
        array $quantities,
        ?int $actorId = null,
    ): void;
}
