<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Policies;

use App\Core\Presentation\Policies\BasePolicy;
use App\Models\User;
use App\Modules\Identity\Domain\Models\UserDevice;
use Illuminate\Database\Eloquent\Model;

/**
 * Devices belong to their user and nobody else.
 *
 * A distinct class from `UserSessionPolicy` — the two shared one until devices
 * got their own endpoints, and a device list carries different data (recognised
 * hardware, trust state) that warrants its own audit surface.
 *
 * Reads are ownership-scoped, not just writes: a device list is browser
 * fingerprints and last-seen IPs, and reading someone else's is a privacy
 * breach even without touching it.
 *
 * @extends BasePolicy<UserDevice>
 */
final class DevicePolicy extends BasePolicy
{
    protected function permissionPrefix(): string
    {
        return 'device';
    }

    /**
     * @param  UserDevice  $model
     */
    protected function owns(User $user, Model $model): bool
    {
        return $model->user_id === $user->getKey();
    }

    /**
     * @return array<int, string>
     */
    protected function ownershipRequiredFor(): array
    {
        return ['view', 'update', 'delete', 'restore', 'forceDelete'];
    }
}
