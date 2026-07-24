<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Policies;

use App\Core\Presentation\Policies\BasePolicy;
use App\Models\User;
use App\Modules\Identity\Domain\Models\UserSession;
use Illuminate\Database\Eloquent\Model;

/**
 * Sessions and devices. Also used for UserDevice — the ownership rule and the
 * permission set are identical, and a second near-empty class would only
 * create a place for the two to drift apart.
 *
 * ALL THREE ACTOR TYPES hold `session.*`, because every user manages their own
 * sessions from their security page. `owns()` is what confines that to their
 * own rows — without it, holding `session.delete` would let any customer
 * revoke any other customer's sessions.
 *
 * @extends BasePolicy<UserSession>
 */
final class UserSessionPolicy extends BasePolicy
{
    protected function permissionPrefix(): string
    {
        return 'session';
    }

    /**
     * @param  UserSession  $model
     */
    protected function owns(User $user, Model $model): bool
    {
        return $model->user_id === $user->getKey();
    }

    /**
     * Viewing is ownership-scoped too, not just writing.
     *
     * BasePolicy's default only enforces ownership on write verbs, which is
     * right for most resources — an admin browsing a catalogue should see
     * everything. A session list is different: it is IP addresses and device
     * fingerprints, and reading someone else's is a privacy breach even
     * without modifying it.
     *
     * @return array<int, string>
     */
    protected function ownershipRequiredFor(): array
    {
        return ['view', 'update', 'delete', 'restore', 'forceDelete'];
    }
}
