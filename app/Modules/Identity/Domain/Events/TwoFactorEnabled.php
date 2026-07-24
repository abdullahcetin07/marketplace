<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A user completed two-factor enrolment.
 *
 * Security-sensitive: the Activity module records it and the Notification
 * module alerts the account owner, because an enrolment the user did not
 * perform means someone else holds their password.
 */
final class TwoFactorEnabled extends BaseEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userUuid,
        /** Actor-type value, so Audit can attribute the forensic entry to the
         * concrete user subclass. Nullable for older call sites. */
        public readonly ?string $guard = null,
    ) {
        parent::__construct();
    }
}
