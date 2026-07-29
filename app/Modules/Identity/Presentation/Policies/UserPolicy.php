<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Policies;

use App\Core\Presentation\Policies\BasePolicy;
use App\Models\User;
use App\Shared\Enums\UserType;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends BasePolicy<User>
 */
final class UserPolicy extends BasePolicy
{
    /**
     * A user may always read their own record, whatever permissions they hold.
     * Without this, a customer could not load their own profile — they hold no
     * `user.*` permissions at all.
     *
     * @param  User  $model
     */
    public function view(User $user, Model $model): Response
    {
        if ($user->is($model)) {
            return Response::allow();
        }

        return parent::view($user, $model);
    }

    /**
     * @param  User  $model
     */
    public function update(User $user, Model $model): Response
    {
        // Editing your own profile is not the same permission as
        // administering other people's accounts.
        if ($user->is($model)) {
            return Response::allow();
        }

        if ($denied = $this->guardAgainstPrivilegeEscalation($user, $model)) {
            return $denied;
        }

        return parent::update($user, $model);
    }

    /**
     * @param  User  $model
     */
    public function delete(User $user, Model $model): Response
    {
        // Deleting yourself would orphan the session performing the delete and
        // can strand a platform with no remaining super-admin.
        if ($user->is($model)) {
            return Response::deny(__('errors.cannot_delete_self'));
        }

        if ($denied = $this->guardAgainstPrivilegeEscalation($user, $model)) {
            return $denied;
        }

        return parent::delete($user, $model);
    }

    /**
     * Impersonation is deliberately its own permission, not part of
     * `user.update`. Editing an account and BECOMING it are different powers.
     *
     * @param  User  $model
     */
    public function impersonate(User $user, Model $model): Response
    {
        if ($user->is($model)) {
            return Response::deny(__('errors.cannot_impersonate_self'));
        }

        // Impersonating another admin would let one admin inherit another's
        // roles and act as them — an audit-trail laundering path.
        if ($model->type === UserType::Admin) {
            return Response::deny(__('errors.cannot_impersonate_admin'));
        }

        return $this->ability($user, 'user.impersonate');
    }

    /**
     * @param  User  $model
     */
    public function assignRoles(User $user, Model $model): Response
    {
        if ($denied = $this->guardAgainstPrivilegeEscalation($user, $model)) {
            return $denied;
        }

        return $this->ability($user, 'user.assign_roles');
    }

    /**
     * Clearing someone's 2FA is a support action AND an account-takeover
     * vector — it is exactly what an attacker with helpdesk access would do.
     * Separate permission, separate audit entry.
     *
     * @param  User  $model
     */
    public function disableTwoFactor(User $user, Model $model): Response
    {
        // Stripping a super-admin's second factor is the first move in taking
        // the platform owner's account — the same escalation guard applies.
        if ($denied = $this->guardAgainstPrivilegeEscalation($user, $model)) {
            return $denied;
        }

        return $this->ability($user, 'user.disable_two_factor');
    }

    public function viewLoginHistory(User $user, Model $model): Response
    {
        if ($user->is($model)) {
            return Response::allow();
        }

        return $this->ability($user, 'user.view_login_history');
    }

    /**
     * Triggering a reset on someone else's account is its own permission —
     * support holds it, and it is the one account-recovery lever a helpdesk
     * needs. Guarded against acting on a super-admin.
     *
     * @param  User  $model
     */
    public function resetPassword(User $user, Model $model): Response
    {
        if ($denied = $this->guardAgainstPrivilegeEscalation($user, $model)) {
            return $denied;
        }

        return $this->ability($user, 'user.reset_password');
    }

    /*
    |--------------------------------------------------------------------------
    | Area abilities — which account SURFACE an operator may open
    |--------------------------------------------------------------------------
    |
    | The admin panel splits accounts into three areas by actor type, and the
    | three are not the same job: provisioning colleagues and granting them
    | staff roles is not the same power as answering a merchant's ticket. One
    | `user.view_any` grant could not express that difference — it opened all
    | three at once — so each area carries its own ability.
    |
    | These gate the AREA. Every per-record decision still goes through the
    | abilities above, including the super-admin escalation guard.
    */

    public function manageStaff(User $user): Response
    {
        return $this->ability($user, 'user.manage_staff');
    }

    public function overseeSellers(User $user): Response
    {
        return $this->ability($user, 'user.oversee_sellers');
    }

    public function overseeCustomers(User $user): Response
    {
        return $this->ability($user, 'user.oversee_customers');
    }

    protected function permissionPrefix(): string
    {
        return 'user';
    }

    /**
     * A non-super-admin must not be able to act on a super-admin.
     *
     * Without this an Editor holding `user.update` could change the platform
     * owner's email and take the account over. This is the one place a ROLE is
     * checked rather than a permission, and it is justified: "is more
     * privileged than me" is not expressible as a permission.
     */
    private function guardAgainstPrivilegeEscalation(User $user, User $target): ?Response
    {
        if ($target->isSuperAdmin() && ! $user->isSuperAdmin()) {
            return Response::deny(__('errors.cannot_modify_super_admin'));
        }

        return null;
    }

    private function ability(User $user, string $permission): Response
    {
        return $user->hasPermissionTo($permission, $user->guardName())
            ? Response::allow()
            : Response::deny(__('errors.missing_permission', ['permission' => $permission]));
    }
}
