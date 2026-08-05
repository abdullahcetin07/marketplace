<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Presentation\Policies;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Models\User;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Shared\Enums\UserType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Who may see a parcel, and who may hand it over (ADR-063/064, Shipping.md §6).
 *
 * **THE RULE THIS CLASS EXISTS FOR: A SELLER CANNOT DELIVER.** ADR-064 — payout
 * waits on delivery, so a seller asserting it is a seller declaring their own
 * payday. `deliver()` denies them unconditionally, whatever role their
 * organization gave them.
 *
 * **BUT THE REAL GUARANTEE IS STRUCTURAL, NOT THIS METHOD**, and the distinction
 * matters enough to state. `Gate::before()` in `AuthServiceProvider` grants a
 * Super Admin every ability before any policy is consulted — "Super Admin
 * bypasses every policy" is a platform rule (CLAUDE.md), and a module carving
 * itself out of it would make this the single place where the bypass is not what
 * it says. So the refusal that actually holds is that **S1 contains no action, no
 * route, no form and no permission that writes `delivered_at`** — the same
 * reasoning `InventoryPolicy::update()` states for stock. An operation that does
 * not exist is a stronger guarantee than a permission nobody can spend.
 *
 * The method is stated anyway, so an attempt to add a "teslim edildi" button
 * later meets a documented denial instead of a missing method falling through to
 * a framework default. The admin's *corrective* mark-delivered that Shipping.md
 * §6 anticipates — for a mis-swept transit auto-delivery — belongs to S2, beside
 * the sweep that can produce the mistake.
 *
 * MEMBERSHIP-SCOPED THROUGH THE ORGANIZATION UUID. A shipment carries
 * `seller_org_uuid` while `OrganizationAuthorizationContract` answers memberships
 * in internal IDS, so the actor's org ids are mapped to uuids and compared. That
 * indirection is the price of Shipping importing no module, and it is paid in one
 * place rather than per call site.
 *
 * SHIPPING IS A MANAGEMENT ACTION, VIEWING IS NOT. Any active member may see what
 * the company has to send — a warehouse hand needs the list — but declaring a
 * parcel handed over is an operational commitment that starts the clock on
 * delivery, so it takes the management capability.
 *
 * @see docs/modules/Shipping.md §3, §6
 */
final class ShipmentPolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly OrganizationAuthorizationContract $authz,
    ) {}

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): Response
    {
        if ($user->type === UserType::Admin) {
            return $this->adminAbility($user, 'shipment.view_any');
        }

        // A seller reaching their own panel is confined by the resource's query
        // scope, not by a permission they cannot hold.
        return $user->type === UserType::Seller
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    public function view(User $user, Shipment $shipment): Response
    {
        if ($user->type === UserType::Admin) {
            return $this->adminAbility($user, 'shipment.view');
        }

        return $this->belongsToUser($user, $shipment)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    /**
     * "Kargoya ver" — the seller's one lever in this module.
     *
     * SELLERS ONLY. An admin correcting a mis-entered tracking number is a
     * support job with its own audit trail, not this ability; giving it to admins
     * here would make "who said this parcel was handed over" ambiguous, and that
     * is the fact the delivery inference is built on.
     */
    public function ship(User $user, Shipment $shipment): Response
    {
        if ($user->type !== UserType::Seller) {
            return Response::deny(__('errors.forbidden'));
        }

        if (! $shipment->awaitsHandover()) {
            return Response::deny(__('shipping.errors.not_awaiting_handover'));
        }

        return $this->managesSellerOrg($user, $shipment)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    /**
     * Not the seller's to declare, and in S1 nobody's (ADR-064).
     *
     * Delivery is not a permission somebody can be trusted with: it is a FACT
     * about the physical world, and the platform has exactly two honest sources
     * for it — the buyer, and the clock. Both are S2's.
     *
     * @see the class docblock for why the guarantee that actually holds is the
     *      ABSENCE of a delivery operation rather than this denial.
     */
    public function deliver(User $user, Shipment $shipment): Response
    {
        return Response::deny(__('shipping.errors.seller_cannot_deliver'));
    }

    /**
     * Nobody deletes a shipment. It is a record of a physical thing having
     * happened, and it is what a payout dispute is read from.
     */
    public function delete(User $user, Shipment $shipment): Response
    {
        return Response::deny(__('shipping.errors.never_deleted'));
    }

    /**
     * Whether the actor belongs to the company that has to send this parcel.
     */
    private function belongsToUser(User $user, Shipment $shipment): bool
    {
        return in_array($shipment->seller_org_uuid, $this->sellerOrgUuids($user), true);
    }

    /**
     * The stricter half: a member who may act for the company, not merely read.
     */
    private function managesSellerOrg(User $user, Shipment $shipment): bool
    {
        foreach ($this->authz->organizationIdsForUser((int) $user->getKey()) as $organizationId) {
            if ($this->authz->organizationUuidFor($organizationId) !== $shipment->seller_org_uuid) {
                continue;
            }

            return $this->authz->canManageOrganization((int) $user->getKey(), $organizationId);
        }

        return false;
    }

    /**
     * Every organization uuid the actor is an active member of.
     *
     * @return array<int, string>
     */
    private function sellerOrgUuids(User $user): array
    {
        $uuids = [];

        foreach ($this->authz->organizationIdsForUser((int) $user->getKey()) as $organizationId) {
            $uuid = $this->authz->organizationUuidFor($organizationId);

            if ($uuid !== null) {
                $uuids[] = $uuid;
            }
        }

        return $uuids;
    }

    private function adminAbility(User $user, string $permission): Response
    {
        return $user->can($permission) ? Response::allow() : Response::deny(__('errors.forbidden'));
    }
}
