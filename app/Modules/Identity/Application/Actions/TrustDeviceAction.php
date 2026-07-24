<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Models\User;
use App\Modules\Identity\Domain\Events\DeviceTrusted;
use App\Modules\Identity\Domain\Models\UserDevice;

/**
 * Mark a device trusted, so it may skip the 2FA challenge for a while.
 *
 * Trust is TIME-LIMITED at the model (`two_factor.trust_days`). This action
 * only stamps `trusted_at`; `UserDevice::isTrusted()` enforces the window on
 * read. Indefinite trust is a permanent 2FA bypass on hardware the user may no
 * longer own — the expiry is the safeguard, not this action.
 *
 * Ownership is checked by the POLICY before this runs. The action re-asserts
 * the owner anyway, as a defence against a future caller that forgets the
 * policy — a service that trusts its caller's scoping is one refactor from an
 * IDOR.
 */
final class TrustDeviceAction extends BaseAction
{
    /**
     * @param  UserDevice  $arguments [0]
     * @param  User  $arguments [1] the acting user (the owner)
     */
    public function handle(mixed ...$arguments): UserDevice
    {
        /** @var UserDevice $device */
        $device = $arguments[0];
        /** @var User $actor */
        $actor = $arguments[1];

        if ($device->user_id !== $actor->getKey()) {
            throw new \DomainException('A device can only be trusted by its owner.');
        }

        $device->trust();

        return $device;
    }

    /**
     * Announce it (§14 — Identity never calls Activity directly; it dispatches,
     * Activity subscribes).
     *
     * The actor's UUID comes from the passed User, not `$device->user` — the
     * relation is not loaded and strict mode would throw on the lazy access.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var UserDevice $result */
        /** @var User $actor */
        $actor = $arguments[1];

        DeviceTrusted::dispatch(
            $actor->getKey(),
            $actor->uuid,
            $result->getKey(),
            $result->label(),
        );
    }
}
