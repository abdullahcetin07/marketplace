<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Policies;

use App\Models\User;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Enums\OrganizationCapability;
use App\Modules\Organization\Domain\Models\OrganizationBankAccount;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Authorises access to the payout bank account (ADR-030).
 *
 * Capability-gated and org-isolated: `bank_account.view` to see it (Owner,
 * Manager, Finance), `bank_account.update` to change it (Owner, Finance). A
 * member of another organization is denied by construction. The full IBAN is
 * never exposed regardless — resources emit only the masked form.
 *
 * @see docs/modules/Organization.md §5.1
 */
final class OrganizationBankAccountPolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly OrganizationMemberRepositoryContract $members,
    ) {}

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function view(User $user, OrganizationBankAccount $account): Response
    {
        return $this->decide($user, $account, OrganizationCapability::BankAccountView);
    }

    public function update(User $user, OrganizationBankAccount $account): Response
    {
        return $this->decide($user, $account, OrganizationCapability::BankAccountUpdate);
    }

    private function decide(User $user, OrganizationBankAccount $account, OrganizationCapability $capability): Response
    {
        $membership = $this->members->findMembership($account->organization_id, $user->getKey());

        return $membership !== null && $membership->can($capability)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }
}
