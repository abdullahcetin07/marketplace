<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Policies;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Models\User;
use App\Modules\Store\Domain\Models\Store;
use App\Shared\Enums\UserType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Authorises storefront actions (ADR-030 isolation, ADR-033).
 *
 * Seller side — resolved through the Core `OrganizationAuthorizationContract`
 * against the STORE'S organization, so a member of another org is denied and
 * Store never imports Organization's membership model:
 *   view                       → active member
 *   update/activate/pause/
 *   resume/close               → canManageOrganization (StoreManage)
 *
 * Admin side — cross-org Spatie permissions (a platform power, not a membership):
 *   viewAny/view/suspend/reinstate → `store.*`
 *
 * (Domain management is out of v1 scope — path-addressed stores, ADR-035.)
 *
 * @see docs/modules/Store.md §9
 */
final class StorePolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly OrganizationAuthorizationContract $authz,
    ) {}

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function view(User $user, Store $store): Response
    {
        // An admin views any store through the platform permission; a seller
        // views only stores of an org they actively belong to (ADR-030).
        if ($user->type === UserType::Admin) {
            return $this->adminAbility($user, 'store.view');
        }

        return $this->authz->isActiveMember($user->getKey(), $store->organization_id)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    public function update(User $user, Store $store): Response
    {
        return $this->manage($user, $store);
    }

    public function activate(User $user, Store $store): Response
    {
        return $this->manage($user, $store);
    }

    public function pause(User $user, Store $store): Response
    {
        return $this->manage($user, $store);
    }

    public function resume(User $user, Store $store): Response
    {
        return $this->manage($user, $store);
    }

    public function close(User $user, Store $store): Response
    {
        return $this->manage($user, $store);
    }

    public function viewAny(User $user): Response
    {
        return $this->adminAbility($user, 'store.view_any');
    }

    public function suspend(User $user, Store $store): Response
    {
        return $this->adminAbility($user, 'store.suspend');
    }

    public function reinstate(User $user, Store $store): Response
    {
        return $this->adminAbility($user, 'store.reinstate');
    }

    public function archive(User $user, Store $store): Response
    {
        return $this->adminAbility($user, 'store.archive');
    }

    private function manage(User $user, Store $store): Response
    {
        return $this->authz->canManageOrganization($user->getKey(), $store->organization_id)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    private function adminAbility(User $user, string $permission): Response
    {
        return $user->hasPermissionTo($permission, $user->guardName())
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }
}
