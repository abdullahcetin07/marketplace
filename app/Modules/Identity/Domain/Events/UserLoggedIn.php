<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A successful authentication.
 *
 * `newDevice` is the security-relevant flag: a sign-in from an unrecognised
 * device is what triggers a "new login to your account" notification. Computing
 * it at dispatch time rather than in each listener means every consumer agrees
 * on what counted as new.
 */
final class UserLoggedIn extends BaseEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userUuid,
        public readonly string $guard,
        public readonly ?string $ipAddress = null,
        public readonly ?int $deviceId = null,
        public readonly bool $newDevice = false,
    ) {
        parent::__construct();
    }
}
