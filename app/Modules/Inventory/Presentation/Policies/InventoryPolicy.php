<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Policies;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Models\User;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Shared\Enums\UserType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Authorises stock — and the striking thing is how little it authorises (§7).
 *
 * THERE IS NO WRITE ABILITY FOR ANYBODY. A seller changes stock on the OFFER
 * form (owner decision, ADR-048) and Inventory mirrors it; an admin does not
 * touch a merchant's stock at all, for the same reason they cannot re-price a
 * merchant's offer — that would be trading on their behalf, and the ledger would
 * name the wrong party. Reservations are moved by Order through the Core command
 * contract, which is machine-to-machine and answers to no policy.
 *
 * So both audiences get READ, by two different mechanisms:
 *
 *  - SELLER: an ORGANIZATION CAPABILITY through the Core contract. Org
 *    capabilities are not Spatie permissions (Spatie `teams` is false), so there
 *    is no `inventory.view` a seller could hold that would be scoped to one
 *    company. Membership of the stock's own org is the wall (ADR-030).
 *  - ADMIN: `inventory.*` Spatie permissions, because cross-org oversight is a
 *    platform power and an admin is not a member of anyone's company.
 *
 * READING IS THE LIGHTER BAR for a seller: any active member may see what the
 * company holds, without needing the capability to manage anything — the same
 * shape `OfferPolicy::view` uses.
 *
 * @see docs/modules/Inventory.md §7
 */
final class InventoryPolicy
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
            return $this->adminAbility($user, 'inventory.view_any');
        }

        // A seller reaching their own panel is confined by the resource's query
        // scope, not by a permission they cannot hold.
        return $user->type === UserType::Seller
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    public function view(User $user, StockItem $item): Response
    {
        if ($user->type === UserType::Admin) {
            return $this->adminAbility($user, 'inventory.view');
        }

        return $this->authz->isActiveMember($user->getKey(), $item->selling_org_id)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    /**
     * Setting the low-stock warning line — the ONE write a seller has here, and
     * it is a preference rather than a count.
     *
     * Gated on the management capability, not on membership: a Viewer may read
     * what the company holds, but changing what the company gets notified about
     * is an operational decision.
     */
    public function setLowStockThreshold(User $user, StockItem $item): Response
    {
        if ($user->type !== UserType::Seller) {
            // Not an admin's setting to make. What a merchant wants to be warned
            // about is theirs.
            return Response::deny(__('errors.forbidden'));
        }

        return $this->authz->canManageOrganization($user->getKey(), $item->selling_org_id)
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    /**
     * Nobody edits stock here — not a seller, not an admin.
     *
     * Stated as an explicit ability rather than left absent, so an attempt to add
     * an edit screen later meets a documented refusal instead of a missing method
     * that quietly falls through to Filament's default.
     *
     * THE SUPER ADMIN BYPASS STILL REACHES IT, and deliberately so: "Super Admin
     * bypasses every policy" is a platform rule (CLAUDE.md), and carving one
     * ability out of it here would make this module the single place where the
     * bypass is not what it says. So the real refusal is STRUCTURAL rather than
     * policy-shaped — there is no edit page, no form, no action and no route that
     * writes a count, on either panel. A permission nobody can spend is a weaker
     * guarantee than an operation that does not exist.
     */
    public function update(User $user, StockItem $item): Response
    {
        return Response::deny(__('inventory.errors.stock_is_not_edited'));
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
