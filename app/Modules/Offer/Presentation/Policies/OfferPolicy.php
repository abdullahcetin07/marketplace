<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Policies;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Models\User;
use App\Modules\Offer\Domain\Models\Offer;
use App\Shared\Enums\UserType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Authorises offers — two audiences, two entirely different mechanisms (§9).
 *
 * SELLER SIDE is an ORGANIZATION CAPABILITY, resolved through the Core
 * `OrganizationAuthorizationContract` against the offer's own selling org. Org
 * capabilities are not Spatie permissions (Spatie `teams` is false), so there is
 * no `offer.update` a seller could hold; access comes from being an active
 * member of that company with the right role. A member of another org is denied
 * by construction — that is the ADR-030 tenancy wall in policy form, and Offer
 * never imports Organization to enforce it.
 *
 * ADMIN SIDE is a cross-org platform power, so it IS a Spatie permission:
 * `offer.view_any`, `offer.view`, `offer.suspend`, `offer.reinstate`. An admin is
 * not a member of anyone's company and must not be treated as one.
 *
 * WHICH CAPABILITY THE SELLER NEEDS. `canManageOrganization()` — which
 * Organization answers from `StoreManage` (Owner + Manager). Offer.md §9 leaves
 * this open ("register an `OfferManage` capability IF the matrix needs a
 * distinct one"), and it does not: the matrix already grants exactly Owner and
 * Manager, which is exactly who §9 says manages offers. Reusing it means this
 * sprint touches the FROZEN Organization module not at all.
 *
 * The cost, stated so it is a decision and not an accident: pricing and
 * storefront operations now share one capability, so a future need to delegate
 * offers WITHOUT handing over store settings cannot be met by the matrix as it
 * stands. That day is an additive ADR adding `OfferManage`, not a refactor —
 * every call site here goes through this one method.
 *
 * @see docs/modules/Offer.md §9
 */
final class OfferPolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly OrganizationAuthorizationContract $authz,
    ) {}

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    /**
     * The listing. An admin needs the platform permission; a seller reaching
     * their own panel is confined by the resource's query scope, not by a
     * permission they do not hold.
     */
    public function viewAny(User $user): Response
    {
        if ($user->type === UserType::Admin) {
            return $this->adminAbility($user, 'offer.view_any');
        }

        return $user->type === UserType::Seller
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    public function view(User $user, Offer $offer): Response
    {
        if ($user->type === UserType::Admin) {
            return $this->adminAbility($user, 'offer.view');
        }

        // Reading is the lighter bar: any active member of the selling company
        // may see what it sells, even without the capability to change it.
        return $this->authz->isActiveMember($user->getKey(), $offer->selling_org_id)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    /**
     * Whether this actor may list ANYTHING — asked before a create form is
     * offered, when there is no offer yet to check against.
     *
     * Deliberately weak: it answers "do you manage any company at all", and the
     * real check happens per-organization once one is chosen (the action
     * validates the store, the panel scopes the picker). Creating is not an
     * admin act — a platform operator does not sell.
     */
    public function create(User $user): Response
    {
        if ($user->type !== UserType::Seller) {
            return Response::deny(__('errors.forbidden'));
        }

        foreach ($this->authz->organizationIdsForUser($user->getKey()) as $organizationId) {
            if ($this->authz->canManageOrganization($user->getKey(), $organizationId)) {
                return Response::allow();
            }
        }

        return Response::deny(__('errors.forbidden'));
    }

    /**
     * Whether this actor may list on behalf of ONE named company — the check the
     * create flow actually needs, and the one the weak `create()` above stands
     * in for while no company has been chosen.
     */
    public function createFor(User $user, int $organizationId): Response
    {
        if ($user->type !== UserType::Seller) {
            return Response::deny(__('errors.forbidden'));
        }

        return $this->authz->canManageOrganization($user->getKey(), $organizationId)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    public function update(User $user, Offer $offer): Response
    {
        return $this->manage($user, $offer);
    }

    public function pause(User $user, Offer $offer): Response
    {
        return $this->manage($user, $offer);
    }

    public function resume(User $user, Offer $offer): Response
    {
        return $this->manage($user, $offer);
    }

    public function withdraw(User $user, Offer $offer): Response
    {
        return $this->manage($user, $offer);
    }

    /**
     * Oversight. Admin-only by construction — a seller suspending a competitor's
     * offer is the attack this separation exists to prevent, and there is no
     * capability that could grant it.
     */
    public function suspend(User $user, Offer $offer): Response
    {
        return $this->adminAbility($user, 'offer.suspend');
    }

    public function reinstate(User $user, Offer $offer): Response
    {
        return $this->adminAbility($user, 'offer.reinstate');
    }

    /**
     * The seller write gate: an active member of THIS offer's company holding
     * the management capability there.
     *
     * An admin is refused deliberately. Editing a merchant's price is not
     * oversight — it is trading on their behalf, and the audit trail would name
     * the wrong party. Suspension is the lever an admin gets.
     */
    private function manage(User $user, Offer $offer): Response
    {
        if ($user->type !== UserType::Seller) {
            return Response::deny(__('errors.forbidden'));
        }

        return $this->authz->canManageOrganization($user->getKey(), $offer->selling_org_id)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    private function adminAbility(User $user, string $permission): Response
    {
        if ($user->type !== UserType::Admin) {
            return Response::deny(__('errors.forbidden'));
        }

        return $user->hasPermissionTo($permission, $user->guardName())
            ? Response::allow()
            : Response::deny(__('errors.missing_permission', ['permission' => $permission]));
    }
}
