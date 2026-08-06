<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Which end of the lifecycle a refund happened at (ADR-065, C1).
 *
 * **THE MONEY IS IDENTICAL AND THE MEANING IS NOT.** A return and a
 * pre-shipment cancellation both run `RefundLinesAction` — same kuruş, same
 * proportional commission, same restock, same two ledger entries — because the
 * arithmetic of undoing a sale does not depend on why it was undone. What differs
 * is everything downstream: a fully returned order becomes `refunded` and its
 * parcel `returned`; a fully cancelled one becomes `cancelled` and its parcel
 * `cancelled`. Nothing in the amounts could tell a consumer which.
 *
 * **IT IS CARRIED ON `PaymentRefunded` AS A STRING, NOT AS THIS ENUM.** The
 * consumers are Order and Shipping, subscribing by class-string precisely so they
 * import nothing from Payment — an enum on the payload would undo that in one
 * type hint. The event's SHAPE is the contract, and a scalar is the only shape it
 * can carry.
 *
 * WHY NOT TWO EVENTS. Because both listeners in Order would then have to agree
 * about which one wins: one event setting `Refunded` and another setting
 * `Cancelled` for the same operation is a race decided by listener registration
 * order. One event with a cause has one answer.
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 *
 * @see App\Modules\Payment\Domain\Events\PaymentRefunded
 */
enum RefundCause: string
{
    use HasEnumHelpers;

    /**
     * The buyer sent delivered goods back inside the return window (ADR-064, S4).
     */
    case Return = 'return';

    /**
     * The order was undone before the parcel was ever handed over (ADR-065).
     */
    case Cancellation = 'cancellation';
}
