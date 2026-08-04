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
 * REFUND IS ADMIN-ONLY IN V1, AND THAT IS A NARROWING OF THE SPEC — reported,
 * not decided quietly. Payment.md §8 allows "admin, or a customer-cancel that the
 * policy allows", and this policy allows only the admin. The reason is that the
 * second half cannot be evaluated yet: whether a customer may reverse their own
 * purchase depends on whether it has SHIPPED, and there is no fulfilment state on
 * this platform — Shipping does not exist. A self-serve refund button that cannot
 * tell "cancel before dispatch" from "return after delivery" would be granting a
 * business rule nobody has written down.
 *
 * The seam is left in the right place: `RefundPaymentAction` takes an actor id and
 * does not care what type of user it is, so when Shipping ships, only this method
 * changes.
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
     * Sending the money back. @see the class docblock for why this is admin-only
     * in v1 and where the customer path will attach.
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
