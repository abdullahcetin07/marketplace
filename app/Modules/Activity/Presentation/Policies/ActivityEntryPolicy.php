<?php

declare(strict_types=1);

namespace App\Modules\Activity\Presentation\Policies;

use App\Models\User;
use App\Modules\Activity\Domain\Models\ActivityEntry;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Activity is read-only, like the audit trail — but unlike the audit trail,
 * every user may read their OWN timeline. That is the point of a security page:
 * a user noticing a login they did not make is the cheapest intrusion detection
 * available.
 *
 * Reading someone ELSE's activity requires `activity.view_any`.
 */
final class ActivityEntryPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    /**
     * Listing is always allowed — the CONTROLLER scopes the query to the
     * actor's own entries unless they hold `activity.view_any`. Denying here
     * would block a user from their own security page.
     */
    public function viewAny(User $user): Response
    {
        return $this->ability($user, 'activity.view');
    }

    public function view(User $user, ActivityEntry $entry): Response
    {
        if ($entry->user_id === $user->getKey()) {
            // Internal entry types (an admin changing this user's roles) are
            // excluded by the userVisible scope, not merely hidden by the view.
            return $entry->type->isUserVisible()
                ? Response::allow()
                : $this->ability($user, 'activity.view_any');
        }

        return $this->ability($user, 'activity.view_any');
    }

    public function export(User $user): Response
    {
        return $this->ability($user, 'activity.export');
    }

    public function create(User $user): Response
    {
        return Response::deny(__('errors.activity_immutable'));
    }

    public function update(User $user, ActivityEntry $entry): Response
    {
        return Response::deny(__('errors.activity_immutable'));
    }

    public function delete(User $user, ActivityEntry $entry): Response
    {
        return Response::deny(__('errors.activity_immutable'));
    }

    private function ability(User $user, string $permission): Response
    {
        return $user->hasPermissionTo($permission, $user->guardName())
            ? Response::allow()
            : Response::deny(__('errors.missing_permission', ['permission' => $permission]));
    }
}
