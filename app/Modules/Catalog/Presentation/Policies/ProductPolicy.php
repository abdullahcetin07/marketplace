<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Policies;

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Core\Presentation\Policies\BasePolicy;
use App\Models\User;
use App\Modules\Catalog\Domain\Models\Product;
use App\Shared\Enums\UserType;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Authorises product authoring and moderation (§9).
 *
 * TWO AUDIENCES, TWO RULES:
 *
 *   Seller — `catalog.products.author`, and ONLY their own proposals. Ownership
 *            is resolved through the Core OrganizationAuthorizationContract
 *            against `proposed_by_org_id`, so Catalog never imports an
 *            Organization model (ADR-040) and a member of another company is
 *            denied.
 *   Staff  — `catalog.products.moderate` for the queue verdicts. A cross-org
 *            platform power, not a membership, so it is a plain permission.
 *
 * `owns()` IS OVERRIDDEN, which it must be: BasePolicy defaults it to false and
 * this policy is reachable from the seller panel, so leaving the default would
 * deny every seller everything (loudly — the right failure direction, but not
 * the intended behaviour).
 *
 * OWNERSHIP IS NOT ENOUGH FOR AN EDIT. A seller owns their published product's
 * provenance forever, but a published product belongs to the shared catalog and
 * is nobody's to edit from a seller panel (ADR-037). That is why update() asks
 * the status as well as the owner.
 *
 * @extends BasePolicy<Product>
 */
final class ProductPolicy extends BasePolicy
{
    public function __construct(
        private readonly OrganizationAuthorizationContract $authz,
    ) {}

    /**
     * Staff read the catalog through the moderation permission; a seller's
     * listing is scoped by the resource query, not by this answer.
     */
    public function viewAny(User $user): Response
    {
        return $this->allowIf($user, 'viewAny');
    }

    public function view(User $user, Model $model): Response
    {
        if ($this->isModerator($user)) {
            return Response::allow();
        }

        return $this->owns($user, $model)
            ? Response::allow()
            : Response::deny(__('errors.not_owner'));
    }

    public function create(User $user): Response
    {
        return $this->allowIf($user, 'create');
    }

    /**
     * The seller may edit their own proposal only while it is in a
     * seller-editable state (§3.1) — never while a moderator is reviewing it,
     * and never once it is published into the shared catalog.
     */
    public function update(User $user, Model $model): Response
    {
        /** @var Product $model */
        if ($this->isModerator($user)) {
            return Response::allow();
        }

        if (! $this->owns($user, $model)) {
            return Response::deny(__('errors.not_owner'));
        }

        return $model->status->isSellerEditable()
            ? Response::allow()
            : Response::deny(__('errors.forbidden'));
    }

    public function submit(User $user, Product $product): Response
    {
        return $this->update($user, $product);
    }

    public function moderate(User $user, Product $product): Response
    {
        return $this->moderatorAbility($user);
    }

    public function publish(User $user, Product $product): Response
    {
        return $this->moderatorAbility($user);
    }

    public function reject(User $user, Product $product): Response
    {
        return $this->moderatorAbility($user);
    }

    public function requestRevision(User $user, Product $product): Response
    {
        return $this->moderatorAbility($user);
    }

    /**
     * Delisting is a moderator power: an archived product is one future Offers
     * can no longer be attached to, which is a platform decision.
     */
    public function archive(User $user, Product $product): Response
    {
        return $this->moderatorAbility($user);
    }

    protected function permissionPrefix(): string
    {
        return 'catalog.products';
    }

    /**
     * Provenance, resolved through the Core contract (ADR-040).
     *
     * A seller may belong to several organizations (ADR-030), so this asks
     * about the LIST, not a single id. A product with no proposer — authored by
     * staff — belongs to no seller and falls out as false.
     *
     * @param Product $model
     */
    protected function owns(User $user, Model $model): bool
    {
        if ($model->proposed_by_org_id === null) {
            return false;
        }

        return $model->isProposedByAny(
            $this->authz->organizationIdsForUser($user->getKey()),
        );
    }

    private function isModerator(User $user): bool
    {
        return $user->type === UserType::Admin
            && $user->hasPermissionTo('catalog.products.moderate', $user->guardName());
    }

    private function moderatorAbility(User $user): Response
    {
        return $user->hasPermissionTo('catalog.products.moderate', $user->guardName())
            ? Response::allow()
            : Response::deny(__('errors.missing_permission', ['permission' => 'catalog.products.moderate']));
    }
}
