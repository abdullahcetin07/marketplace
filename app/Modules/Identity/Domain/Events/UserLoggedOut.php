<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A session ended.
 *
 * `reason` separates a deliberate sign-out from a forced one. "Logged out" and
 * "your session was revoked from another device" look identical in a log
 * without it, and they mean very different things during an incident.
 */
final class UserLoggedOut extends BaseEvent
{
    public const string REASON_MANUAL = 'manual';
    public const string REASON_REVOKED = 'revoked';
    public const string REASON_EXPIRED = 'expired';
    public const string REASON_PASSWORD_CHANGED = 'password_changed';
    public const string REASON_ALL_DEVICES = 'all_devices';

    public function __construct(
        public readonly int $userId,
        public readonly string $userUuid,
        public readonly string $guard,
        public readonly string $reason = self::REASON_MANUAL,
    ) {
        parent::__construct();
    }
}
