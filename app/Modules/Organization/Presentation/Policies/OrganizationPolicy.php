<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Policies;

use App\Core\Presentation\Policies\BasePolicy;
use App\Models\User;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Enums\OrganizationCapability;
use App\Modules\Organization\Domain\Models\Organization;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends BasePolicy<Organization>
 *
 * Partial (Phase 1): the admin lifecycle abilities and owner-scoped reads. The
 * member-scoped capabilities (§5 matrix) arrive with Phase 2, at which point
 * `owns()` widens from "is the owner" to "is a member".
 *
 * The un-suspend ability is named `reinstate`, not `restore`, because
 * `BasePolicy::restore` already means the soft-delete restore verb — a distinct
 * operation. Keeping them separate avoids one word meaning two things.
 */
final class OrganizationPolicy extends BasePolicy
{
    public function __construct(
        private readonly OrganizationMemberRepositoryContract $members,
    ) {}

    /**
     * A seller may read their OWN organization; an admin reads any with the
     * permission. Org data is private (KYC, bank), so `view` is ownership-gated
     * for non-admins — hence the override of ownershipRequiredFor() below.
     *
     * @param  Organization  $model
     */
    public function view(User $user, Model $model): Response
    {
        if ($this->owns($user, $model)) {
            return Response::allow();
        }

        return parent::view($user, $model);
    }

    /**
     * @param  Organization  $model
     */
    public function approve(User $user, Model $model): Response
    {
        return $this->allowIf($user, 'approve', $model);
    }

    /**
     * @param  Organization  $model
     */
    public function reject(User $user, Model $model): Response
    {
        return $this->allowIf($user, 'reject', $model);
    }

    /**
     * @param  Organization  $model
     */
    public function suspend(User $user, Model $model): Response
    {
        return $this->allowIf($user, 'suspend', $model);
    }

    /**
     * Un-suspend. @param Organization $model
     */
    public function reinstate(User $user, Model $model): Response
    {
        return $this->allowIf($user, 'reinstate', $model);
    }

    /*
    |----------------------------------------------------------------------
    | Seller-side, capability-based (ADR-030). Each resolves the actor's
    | membership of THIS organization and checks the capability; a non-member
    | is denied by construction. `update` also falls through to the admin Spatie
    | ability so an admin holding `organization.update` still passes.
    |----------------------------------------------------------------------
    */

    /**
     * @param  Organization  $model
     */
    public function update(User $user, Model $model): Response
    {
        if ($this->memberCan($user, $model, OrganizationCapability::OrganizationUpdate)) {
            return Response::allow();
        }

        return $this->allowIf($user, 'update', $model);
    }

    /**
     * @param  Organization  $model
     */
    public function manageKyc(User $user, Model $model): Response
    {
        return $this->capabilityResponse($user, $model, OrganizationCapability::OrganizationManageKyc);
    }

    /**
     * @param  Organization  $model
     */
    public function inviteMembers(User $user, Model $model): Response
    {
        return $this->capabilityResponse($user, $model, OrganizationCapability::MemberInvite);
    }

    /**
     * @param  Organization  $model
     */
    public function createStoreRequest(User $user, Model $model): Response
    {
        return $this->capabilityResponse($user, $model, OrganizationCapability::StoreRequestCreate);
    }

    /**
     * @param  Organization  $model
     */
    public function transferOwnership(User $user, Model $model): Response
    {
        return $this->capabilityResponse($user, $model, OrganizationCapability::OwnershipTransfer);
    }

    /**
     * Admin sets the store-limit override / plan. Admin Spatie ability.
     *
     * @param  Organization  $model
     */
    public function manageLimit(User $user, Model $model): Response
    {
        return $this->allowIf($user, 'manageLimit', $model);
    }

    protected function permissionPrefix(): string
    {
        return 'organization';
    }

    private function capabilityResponse(User $user, Organization $organization, OrganizationCapability $capability): Response
    {
        return $this->memberCan($user, $organization, $capability)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    private function memberCan(User $user, Organization $organization, OrganizationCapability $capability): bool
    {
        $membership = $this->members->findMembership($organization->getKey(), $user->getKey());

        return $membership !== null && $membership->can($capability);
    }

    /**
     * The seller-side tenancy check (ADR-030): a user "owns" — may access — an
     * organization when they are its Owner OR an active member of it. Anyone
     * with no membership is confined out by construction. Finer capability
     * gating (who may update vs merely view) lands with the seller API in
     * Phase 6; this establishes the isolation wall.
     *
     * @param  Organization  $model
     */
    protected function owns(User $user, Model $model): bool
    {
        return $model->owner_id === $user->getKey()
            || $this->members->isActiveMember($model->getKey(), $user->getKey());
    }

    /**
     * Reads of an organization are ownership-gated for non-admins — a seller
     * must not see another company's KYC or bank details. (The base allows
     * reads platform-wide, which is wrong for a private aggregate.)
     *
     * @return array<int, string>
     */
    protected function ownershipRequiredFor(): array
    {
        return ['view', 'update', 'delete', 'restore', 'forceDelete'];
    }
}
