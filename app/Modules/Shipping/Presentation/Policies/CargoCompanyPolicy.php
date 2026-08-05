<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Presentation\Policies;

use App\Models\User;
use App\Modules\Shipping\Domain\Models\CargoCompany;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Who maintains the carrier list (ADR-063, Shipping.md §5).
 *
 * ADMIN ONLY, BY PERMISSION (non-negotiable #5). A carrier is platform
 * configuration — which companies the business has contracts with — not a
 * seller's choice; a seller PICKS from the list and never edits it.
 *
 * IT IS A FULL RESOURCE, unlike `shipment.*` which is read-plus-one-verb, because
 * this is exactly the "operator changes it without a release" case the lookup-
 * table rule exists for: a new carrier, a changed tracking URL, a retirement.
 *
 * NO DELETE FOR ANYBODY. A shipment names its carrier and the FK restricts;
 * withdrawal is `is_active = false`, which keeps every parcel's history readable.
 *
 * @see docs/modules/Shipping.md §5
 */
final class CargoCompanyPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): Response
    {
        return $this->ability($user, 'cargo_company.view_any');
    }

    public function view(User $user, CargoCompany $company): Response
    {
        return $this->ability($user, 'cargo_company.view');
    }

    public function create(User $user): Response
    {
        return $this->ability($user, 'cargo_company.create');
    }

    public function update(User $user, CargoCompany $company): Response
    {
        return $this->ability($user, 'cargo_company.update');
    }

    /**
     * Never. @see the class docblock.
     */
    public function delete(User $user, CargoCompany $company): Response
    {
        return Response::deny(__('shipping.cargo.never_deleted'));
    }

    private function ability(User $user, string $permission): Response
    {
        return $user->can($permission) ? Response::allow() : Response::deny(__('errors.forbidden'));
    }
}
