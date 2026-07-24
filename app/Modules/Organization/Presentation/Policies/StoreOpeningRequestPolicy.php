<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Policies;

use App\Models\User;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Enums\OrganizationCapability;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Authorises Store Opening Request actions (ADR-028, ADR-030).
 *
 * Seller side — org capabilities, isolated to the request's organization:
 *   view/submit → `store_request.create` (a member who may raise one)
 *   cancel      → `store_request.cancel`
 * Admin side — cross-org Spatie permissions:
 *   approve     → `store_request.approve`
 *   reject      → `store_request.reject`
 *
 * @see docs/modules/Organization.md §7, §9
 */
final class StoreOpeningRequestPolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly OrganizationMemberRepositoryContract $members,
    ) {}

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function view(User $user, StoreOpeningRequest $request): Response
    {
        return $this->members->isActiveMember($request->organization_id, $user->getKey())
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    public function submit(User $user, StoreOpeningRequest $request): Response
    {
        return $this->capability($user, $request, OrganizationCapability::StoreRequestCreate);
    }

    public function cancel(User $user, StoreOpeningRequest $request): Response
    {
        return $this->capability($user, $request, OrganizationCapability::StoreRequestCancel);
    }

    /**
     * The admin review queue (class-level — no specific request).
     */
    public function viewQueue(User $user): Response
    {
        return $this->adminAbility($user, 'store_request.approve');
    }

    public function approve(User $user, StoreOpeningRequest $request): Response
    {
        return $this->adminAbility($user, 'store_request.approve');
    }

    public function reject(User $user, StoreOpeningRequest $request): Response
    {
        return $this->adminAbility($user, 'store_request.reject');
    }

    private function capability(User $user, StoreOpeningRequest $request, OrganizationCapability $capability): Response
    {
        $membership = $this->members->findMembership($request->organization_id, $user->getKey());

        return $membership !== null && $membership->can($capability)
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
