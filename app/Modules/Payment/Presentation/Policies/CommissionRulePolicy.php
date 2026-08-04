<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Policies;

use App\Models\User;
use App\Modules\Payment\Domain\Models\CommissionRule;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Who may set what the platform takes (ADR-061, Payment.md §6).
 *
 * ADMIN ONLY, AND BY PERMISSION RATHER THAN BY ROLE (non-negotiable #5). A
 * commission rate decides every seller's income; there is no seller-facing view of
 * this at all, and there should not be — a merchant seeing the rule that prices
 * their competitor is a commercial leak, not a transparency feature. So unlike
 * `OrderPolicy`, there is no `default => allow` arm here: anyone who is not an
 * admin holding the permission is refused.
 *
 * NO DELETE FOR ANYBODY, not even a Super Admin's `before()` bypass caring: a rule
 * that has priced real sales is the explanation for money already taken.
 * Deactivating it stops it applying to the next sale while leaving that
 * explanation intact, and the commission itself is frozen on the order line either
 * way (ADR-061) — so deleting could never move a settled figure, only erase why it
 * was what it was.
 *
 * @see docs/modules/Payment.md §6
 */
final class CommissionRulePolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): Response
    {
        return $this->ability($user, 'commission_rule.view_any');
    }

    public function view(User $user, CommissionRule $rule): Response
    {
        return $this->ability($user, 'commission_rule.view');
    }

    public function create(User $user): Response
    {
        return $this->ability($user, 'commission_rule.create');
    }

    public function update(User $user, CommissionRule $rule): Response
    {
        return $this->ability($user, 'commission_rule.update');
    }

    /**
     * Never. @see the class docblock.
     */
    public function delete(User $user, CommissionRule $rule): Response
    {
        return Response::deny(__('payment.commission.never_deleted'));
    }

    private function ability(User $user, string $permission): Response
    {
        return $user->can($permission)
            ? Response::allow()
            : Response::deny();
    }
}
