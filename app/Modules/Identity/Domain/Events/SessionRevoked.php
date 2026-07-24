<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * One or more sessions were ended by someone other than the session itself.
 *
 * Distinct from UserLoggedOut, which is a session ending its own life. This is
 * a user revoking a device from their security page, an admin terminating a
 * compromised session, or a password change cascading.
 */
final class SessionRevoked extends BaseEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userUuid,
        /** @var array<int, string> revoked session UUIDs */
        public readonly array $sessionUuids,
        public readonly string $reason,
        /** Null when the user revoked their own; set when an admin did. */
        public readonly ?int $revokedByUserId = null,
        /** Actor-type value, so Audit can attribute the forensic entry to the
         * concrete user subclass. */
        public readonly ?string $guard = null,
    ) {
        parent::__construct();
    }

    public function count(): int
    {
        return count($this->sessionUuids);
    }
}
