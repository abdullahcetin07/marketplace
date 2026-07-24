<?php

declare(strict_types=1);

namespace App\Modules\Audit\Presentation\Policies;

use App\Models\User;
use App\Modules\Audit\Domain\Models\AuditEntry;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * The audit trail is read-only, so this does NOT extend BasePolicy — there is
 * no create, update or delete ability to inherit. Offering them would imply an
 * editable audit trail, which is a contradiction.
 *
 * Reading is itself sensitive: entries contain the before and after values of
 * every audited field, which across the platform means addresses, prices and
 * personal data. Admin-only.
 */
final class AuditEntryPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): Response
    {
        return $this->ability($user, 'audit.view_any');
    }

    public function view(User $user, AuditEntry $entry): Response
    {
        return $this->ability($user, 'audit.view');
    }

    public function export(User $user): Response
    {
        return $this->ability($user, 'audit.export');
    }

    /**
     * Explicit denials rather than omission. An absent method falls through to
     * Gate's default deny, but stating it means a future refactor that adds a
     * permissive `before()` cannot accidentally open them.
     */
    public function create(User $user): Response
    {
        return Response::deny(__('errors.audit_immutable'));
    }

    public function update(User $user, AuditEntry $entry): Response
    {
        return Response::deny(__('errors.audit_immutable'));
    }

    public function delete(User $user, AuditEntry $entry): Response
    {
        return Response::deny(__('errors.audit_immutable'));
    }

    private function ability(User $user, string $permission): Response
    {
        return $user->hasPermissionTo($permission, $user->guardName())
            ? Response::allow()
            : Response::deny(__('errors.missing_permission', ['permission' => $permission]));
    }
}
