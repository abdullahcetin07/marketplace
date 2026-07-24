<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A password was changed or reset.
 *
 * `viaReset` separates a deliberate change (the user knew the old password)
 * from a reset (they proved control of the mailbox instead). Only the second
 * is an account-recovery path an attacker can drive, so they must not look
 * identical in the timeline.
 *
 * Listeners revoke every other session — a password change that leaves the
 * attacker's session alive achieves nothing.
 */
final class PasswordChanged extends BaseEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userUuid,
        public readonly bool $viaReset = false,
        /** Session to keep alive — the one performing the change. */
        public readonly ?string $keepSessionId = null,
        /** Actor-type value, so Audit can attribute the forensic entry to the
         * concrete user subclass. The password column is secret-excluded from
         * the diff trail, so this event is the only forensic record of it. */
        public readonly ?string $guard = null,
    ) {
        parent::__construct();
    }
}
