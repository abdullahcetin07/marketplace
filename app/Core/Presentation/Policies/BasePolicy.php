<?php

declare(strict_types=1);

namespace App\Core\Presentation\Policies;

use App\Models\User;
use App\Shared\Enums\UserType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Root of every authorisation policy.
 *
 * DESIGN RULE — permissions, never roles:
 * A policy asks "does this actor hold permission X?", never "is this actor a
 * Category Manager?". Roles are just bundles of permissions that an operations
 * team reshuffles at runtime; hard-coding one into a policy makes that bundle
 * un-reshuffleable. And role *ids* are never referenced anywhere, because they
 * differ between environments and a seeded id is not a stable identifier.
 *
 * Permission names are derived from the model: `store.view`, `store.create`,
 * `store.update`, `store.delete`, `store.restore`, `store.force_delete`.
 * Override permissionPrefix() when a model's name and its permission namespace
 * differ.
 *
 * Ownership is layered on top: holding `offer.update` lets an admin edit any
 * offer, but a seller with the same permission may only edit their own. That
 * is what owns() is for — override it per policy.
 *
 * @template TModel of Model
 *
 * @see App\Shared\Support\PermissionRegistry
 * @see docs/authorization.md
 */
abstract class BasePolicy
{
    use HandlesAuthorization;

    /**
     * Grant everything to platform super-admins before any other check.
     *
     * Returning null (not false) falls through to the individual ability
     * methods — returning false here would deny everyone else outright.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): Response
    {
        return $this->allowIf($user, 'viewAny');
    }

    /**
     * @param  TModel  $model
     */
    public function view(User $user, Model $model): Response
    {
        return $this->allowIf($user, 'view', $model);
    }

    public function create(User $user): Response
    {
        return $this->allowIf($user, 'create');
    }

    /**
     * @param  TModel  $model
     */
    public function update(User $user, Model $model): Response
    {
        return $this->allowIf($user, 'update', $model);
    }

    /**
     * @param  TModel  $model
     */
    public function delete(User $user, Model $model): Response
    {
        return $this->allowIf($user, 'delete', $model);
    }

    /**
     * @param  TModel  $model
     */
    public function restore(User $user, Model $model): Response
    {
        return $this->allowIf($user, 'restore', $model);
    }

    /**
     * Hard deletes are reserved for admins even with the permission held —
     * an irreversible action should not be reachable from a seller panel.
     *
     * @param  TModel  $model
     */
    public function forceDelete(User $user, Model $model): Response
    {
        if ($user->type !== UserType::Admin) {
            return Response::deny(__('errors.forbidden'));
        }

        return $this->allowIf($user, 'forceDelete', $model);
    }

    /**
     * Permission namespace for this policy, e.g. `store`.
     */
    abstract protected function permissionPrefix(): string;

    /**
     * Whether the actor owns the record.
     *
     * Default is false — a policy that does not implement ownership grants
     * access on permission alone, which is correct for admin-only resources.
     * Any policy exposed to the seller panel MUST override this.
     *
     * @param  TModel  $model
     */
    protected function owns(User $user, Model $model): bool
    {
        return false;
    }

    /**
     * Map a Laravel ability name onto a permission name.
     */
    protected function permissionFor(string $ability): string
    {
        $suffix = match ($ability) {
            'viewAny' => 'view_any',
            'forceDelete' => 'force_delete',
            default => str($ability)->snake()->value(),
        };

        return $this->permissionPrefix().'.'.$suffix;
    }

    /**
     * Abilities that additionally require ownership when the actor is not an
     * admin. Reads are generally allowed platform-wide; writes are not.
     *
     * @return array<int, string>
     */
    protected function ownershipRequiredFor(): array
    {
        return ['update', 'delete', 'restore', 'forceDelete'];
    }

    /**
     * The single decision point: permission first, then ownership.
     *
     * @param  TModel|null  $model
     */
    protected function allowIf(User $user, string $ability, ?Model $model = null): Response
    {
        $permission = $this->permissionFor($ability);

        // Permissions are guard-scoped: an admin's `store.update` and a
        // seller's `store.update` are distinct records with distinct meanings.
        if (! $user->hasPermissionTo($permission, $user->guardName())) {
            return Response::deny(__('errors.missing_permission', ['permission' => $permission]));
        }

        if ($model === null || $user->type === UserType::Admin) {
            return Response::allow();
        }

        if (in_array($ability, $this->ownershipRequiredFor(), true) && ! $this->owns($user, $model)) {
            return Response::deny(__('errors.not_owner'));
        }

        return Response::allow();
    }
}
