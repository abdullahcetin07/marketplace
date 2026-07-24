<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Core\Domain\Events\BaseEvent;
use App\Shared\Enums\UserType;

/**
 * A new account was created, by any route — self-registration, admin
 * provisioning, or the install command.
 *
 * Carries scalars rather than the model. Events are serialised into the audit
 * log and onto the queue; a listener that runs twenty minutes later must not
 * depend on the in-memory state the model had at dispatch time, and a log line
 * containing a full serialised User would leak the password hash.
 */
final class UserCreated extends BaseEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userUuid,
        public readonly UserType $type,
        public readonly string $email,
    ) {
        parent::__construct();
    }
}
