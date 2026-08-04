<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Policies;

use App\Models\User;
use App\Modules\Payment\Domain\Models\Payout;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Who may promise a seller money, and who may say it arrived (ADR-062,
 * Payment.md §8).
 *
 * ADMIN ONLY, BY PERMISSION (non-negotiable #5). There is no seller-facing payout
 * screen in v1 — a merchant sees their BALANCE, not the platform's transfer
 * records — and no seller may create one, for the obvious reason.
 *
 * `create` AND `update` ARE SEPARATE ABILITIES because they are separate jobs in a
 * real finance process: one person decides to send money, another confirms the
 * bank did. Granting them together is a choice a role makes, not one this policy
 * makes for it.
 *
 * NO DELETE FOR ANYBODY. A payout is a record of money leaving; a mistaken one is
 * marked FAILED, which reverses the ledger debit and leaves both facts on the
 * trail. Deleting it would erase a transfer somebody may have actually made.
 *
 * @see docs/modules/Payment.md §8
 */
final class PayoutPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): Response
    {
        return $this->ability($user, 'payout.view_any');
    }

    public function view(User $user, Payout $payout): Response
    {
        return $this->ability($user, 'payout.view');
    }

    public function create(User $user): Response
    {
        return $this->ability($user, 'payout.create');
    }

    /**
     * Recording the bank's answer — the only update a payout permits, and only
     * out of `pending` (`Payout::isSettling()`).
     */
    public function update(User $user, Payout $payout): Response
    {
        return $this->ability($user, 'payout.update');
    }

    /**
     * Never. @see the class docblock.
     */
    public function delete(User $user, Payout $payout): Response
    {
        return Response::deny(__('payment.payout.never_deleted'));
    }

    private function ability(User $user, string $permission): Response
    {
        return $user->can($permission) ? Response::allow() : Response::deny();
    }
}
