<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Policies;

use App\Core\Presentation\Policies\BasePolicy;
use App\Models\User;
use App\Modules\Catalog\Domain\Models\Category;
use Illuminate\Auth\Access\Response;

/**
 * Authorises taxonomy management (ADR-038, §9).
 *
 * ONE PERMISSION FOR THE WHOLE TAXONOMY — `catalog.taxonomy.manage`. Categories,
 * attributes, attribute values and brands are one editorial job held by one
 * role; splitting them into four permissions would create combinations nobody
 * will ever grant (a Category Manager who may add colours but not categories is
 * not a role anyone wants).
 *
 * NO SELLER SURFACE AT ALL. A seller reads the taxonomy through the authoring
 * form and can never write it — that is the ADR-038 bargain, and it is enforced
 * by the permission simply not existing on the seller guard rather than by an
 * ownership check. `owns()` therefore stays at its `false` default, correctly:
 * nobody owns a platform category.
 *
 * @extends BasePolicy<Category>
 */
final class CategoryPolicy extends BasePolicy
{
    /**
     * Reads are open to any authenticated panel user: a seller must see the
     * category tree to file a product against it, and the tree is not secret.
     * Every WRITE goes through manage().
     */
    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, $model): Response
    {
        return Response::allow();
    }

    public function create(User $user): Response
    {
        return $this->manage($user);
    }

    public function update(User $user, $model): Response
    {
        return $this->manage($user);
    }

    public function delete(User $user, $model): Response
    {
        return $this->manage($user);
    }

    /**
     * Deactivation (ADR-015) — the removal a category that MEANT something
     * gets, because products point at it and its slug is a public URL segment.
     *
     * A genuinely empty node can be deleted outright (`delete`); the guards
     * that make the two different live in their actions, not here — both are
     * the Category Manager's to perform.
     */
    public function archive(User $user, Category $category): Response
    {
        return $this->manage($user);
    }

    public function reorder(User $user): Response
    {
        return $this->manage($user);
    }

    protected function permissionPrefix(): string
    {
        return 'catalog.taxonomy';
    }

    private function manage(User $user): Response
    {
        return $user->hasPermissionTo('catalog.taxonomy.manage', $user->guardName())
            ? Response::allow()
            : Response::deny(__('errors.missing_permission', ['permission' => 'catalog.taxonomy.manage']));
    }
}
