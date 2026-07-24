<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * Two-factor authentication was switched off.
 *
 * `byAdministrator` is the important flag. A support operator clearing a user's
 * 2FA is a legitimate recovery path AND exactly what an attacker with helpdesk
 * access would do. The two must be distinguishable in the timeline, and the
 * account owner is notified either way.
 */
final class TwoFactorDisabled extends BaseEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userUuid,
        public readonly bool $byAdministrator = false,
        /** Actor-type value, so Audit can attribute the entry to the concrete
         * user subclass. Nullable for events raised before this was carried. */
        public readonly ?string $guard = null,
    ) {
        parent::__construct();
    }
}
