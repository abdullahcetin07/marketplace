<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Policies;

use App\Models\User;
use App\Modules\Payment\Domain\Models\Payment;
use App\Shared\Enums\UserType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Who may look at a payment, and who may send the money back (Payment.md §3, §8).
 *
 * READING IS OWNERSHIP OR PERMISSION. The buyer sees their own payment because it
 * is theirs — resolved from `customer_id`, not from a permission, the same shape
 * the address book and the order list use. An admin sees any, by ability.
 *
 * NOBODY UPDATES A PAYMENT. There is no `update` here and no `payment.update`
 * ability: a payment is a record of what a bank did, and editing it would make it
 * a record of what somebody typed. The refund is not an edit — it is a new fact,
 * with its own ability and its own rows.
 *
 * **`refund` IS STILL ADMIN-ONLY, AND SINCE S4 THAT NO LONGER MEANS A CUSTOMER
 * CANNOT RETURN ANYTHING.** P5 narrowed Payment.md §8 to admins and said why:
 * whether a buyer may reverse their own purchase depends on whether it SHIPPED,
 * and there was no fulfilment state to ask. Shipping wrote one — a delivery date
 * and a return window (ADR-064) — and S4 opened the buyer's door on top of it.
 *
 * IT DID NOT OPEN IT HERE, WHICH IS THE PART WORTH READING. A buyer's return is
 * not "the admin refund, permitted to a customer": it is bounded by ownership,
 * by delivery and by a clock, and none of those are questions about a PAYMENT —
 * they are questions about an ORDER and its parcel. So they live in
 * `RequestReturnAction`, which reads them through the Core Order port, and this
 * ability keeps meaning exactly what it meant: who may reverse a charge WITHOUT
 * those conditions holding.
 *
 * Two doors to one machine, deliberately. @see `RefundLinesAction`.
 *
 * @see docs/modules/Payment.md §8
 */
final class PaymentPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): Response
    {
        return $this->ability($user, 'payment.view_any');
    }

    /**
     * The buyer's own, or an admin's by permission.
     */
    public function view(User $user, Payment $payment): Response
    {
        if ($user->type === UserType::Customer) {
            return $payment->customer_id === $user->getKey()
                ? Response::allow()
                : Response::deny();
        }

        return $this->ability($user, 'payment.view');
    }

    /**
     * Sending the money back WITHOUT a window to justify it. @see the class
     * docblock for why the buyer's own return is not this ability relaxed.
     */
    public function refund(User $user, Payment $payment): Response
    {
        return $this->ability($user, 'payment.refund');
    }

    /**
     * Never. A payment is a record of what a bank did.
     */
    public function delete(User $user, Payment $payment): Response
    {
        return Response::deny(__('payment.payment.never_deleted'));
    }

    private function ability(User $user, string $permission): Response
    {
        return $user->can($permission) ? Response::allow() : Response::deny();
    }
}
