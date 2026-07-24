<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A password reset was requested for an address that exists.
 *
 * Carries NO token (ADR-025). The event travels through the audit log and the
 * queue; a credential must not.
 *
 * Not dispatched for an unknown address — there is nothing to record against,
 * and firing it would make the event stream an existence oracle in exactly the
 * way the response envelope is careful not to be.
 */
final class PasswordResetRequested extends BaseEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userUuid,
        public readonly string $guard,
        public readonly ?string $ipAddress = null,
    ) {
        parent::__construct();
    }
}
