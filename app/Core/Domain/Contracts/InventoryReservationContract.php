<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * The only sanctioned way another module MUTATES stock (ADR-049).
 *
 * THE PLATFORM'S FIRST COMMAND PORT. Every other Core contract is a read port —
 * a downstream context asking an upstream one a question. This one is the write
 * side, and the asymmetry is deliberate: stock is the one piece of state a
 * second module legitimately has to change, because a checkout that cannot hold
 * a unit is a checkout that oversells.
 *
 * ORDER IS ITS FIRST CALLER, AND ORDER DOES NOT EXIST YET. This sprint the
 * primitives are exercised by tests, not by a real checkout — which is the whole
 * argument for building the authority before its caller (ADR-049): Order will
 * find a tested contract rather than designing reservation semantics in a hurry
 * next to a payment integration.
 *
 * IDEMPOTENT ON `$referenceUuid`, WHICH IS THE CALLER'S OWN KEY. A retried
 * checkout must not take a second hold; a webhook that fires twice must not
 * commit twice. The caller passes its own uuid so it never has to store an
 * Inventory identifier to release what it reserved.
 *
 * THE THREE VERBS AND WHAT EACH MOVES:
 *
 *   reserve  — `reserved` up. `on_hand` untouched: nothing has left, the units
 *              are spoken for. Fails when `available < qty`.
 *   release  — `reserved` down. The hold is given back (cancelled, expired).
 *   commit   — BOTH down. The sale completed and the units truly left.
 *
 * Under a row lock on the stock pool, so two concurrent reserves cannot both
 * take the last unit — the exact race this module exists to prevent (§3.4).
 *
 * THE FAILURES ARE DESCRIBED, NOT TYPE-HINTED. Core may not depend on a module
 * (`LayeringTest`), so this cannot name `InventoryException` — the implementation
 * throws it and the docblocks below say when. A caller that wants to branch reads
 * the `reason` in that exception's context, exactly as it would for any other
 * domain refusal on this platform.
 *
 * @see App\Modules\Inventory\Infrastructure\Commands\InventoryReservation
 * @see docs/modules/Inventory.md §5.2
 */
interface InventoryReservationContract
{
    /**
     * Hold `$quantity` units for the caller's reference.
     *
     * Returns true when the hold stands — including when this reference already
     * held it, because a retry succeeding is the point of idempotency.
     *
     * Throws when the seller has no stock record for the variant, when the
     * quantity is not positive, or when `available < $quantity`. A refusal, not
     * an incident: the last unit going to whoever asked first is the system
     * working.
     */
    public function reserve(
        string $sellingOrgUuid,
        string $variantUuid,
        int $quantity,
        string $referenceUuid,
    ): bool;

    /**
     * Give a hold back. Lowers `reserved` only — nothing physical moved.
     *
     * A reference that is already released or committed is a NO-OP, not an
     * error: that is what makes a retried cancellation safe.
     *
     * Throws when no reservation was ever made under this reference — a caller
     * bug worth surfacing, as distinct from acting twice on a real one.
     */
    public function release(string $referenceUuid): void;

    /**
     * Turn a hold into a sale. Lowers `on_hand` AND `reserved` together.
     *
     * A reference that is already committed is a NO-OP — the guarantee that
     * stops a retried order confirmation decrementing twice.
     *
     * Throws when no reservation exists under this reference.
     */
    public function commit(string $referenceUuid): void;
}
