<?php

declare(strict_types=1);

namespace App\Modules\Notification\Presentation\Policies;

use App\Core\Presentation\Policies\BasePolicy;
use App\Models\User;
use App\Modules\Notification\Domain\Models\NotificationPreference;
use Illuminate\Database\Eloquent\Model;

/**
 * Notification preferences belong to their user and nobody else — not even an
 * administrator, who has no legitimate reason to change what someone else has
 * chosen to receive.
 *
 * @extends BasePolicy<NotificationPreference>
 */
final class NotificationPreferencePolicy extends BasePolicy
{
    protected function permissionPrefix(): string
    {
        return 'notification_preference';
    }

    /**
     * @param NotificationPreference $model
     */
    protected function owns(User $user, Model $model): bool
    {
        return $model->user_id === $user->getKey();
    }

    /**
     * Reads are ownership-scoped too. Unlike most resources, an admin browsing
     * these learns only what a user has muted — which is a preference, not
     * platform data, and is nobody else's business.
     *
     * @return array<int, string>
     */
    protected function ownershipRequiredFor(): array
    {
        return ['view', 'update', 'delete', 'restore', 'forceDelete'];
    }
}
