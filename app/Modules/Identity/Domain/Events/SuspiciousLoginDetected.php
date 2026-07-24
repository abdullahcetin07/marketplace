<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Core\Domain\Events\BaseEvent;
use App\Shared\Enums\LoginThreatKind;

/**
 * A run of failed logins against one address crossed the alarm threshold (Q6).
 *
 * Announced, not acted on. Identity states the fact and stops; three listeners
 * react independently — Audit writes a high-severity forensic entry, Activity
 * adds it to the account's own timeline, and Identity notifies the owner and the
 * administrators. That fan-out is why this is an event and not a method call.
 *
 * THE ACCOUNT MAY NOT EXIST. A stuffing run hits addresses that were never
 * registered. `userId`/`userUuid` are null in that case: there is no owner to
 * notify, but the attempt is still forensic evidence worth a row and an admin
 * alert. Listeners must tolerate the nulls.
 *
 * `email` is carried because the whole point is which address is under attack;
 * it never reaches an API response — it flows only to the mailbox owner, the
 * audit trail and the admin alert.
 *
 * @see App\Modules\Identity\Application\Services\AuthService::flagIfUnderAttack()
 * @see docs/modules/Identity.md §Q6
 */
final class SuspiciousLoginDetected extends BaseEvent
{
    public function __construct(
        public readonly LoginThreatKind $kind,
        public readonly string $email,
        public readonly string $guard,
        public readonly int $failureCount,
        public readonly int $distinctIps,
        public readonly ?string $ipAddress = null,
        public readonly ?int $userId = null,
        public readonly ?string $userUuid = null,
    ) {
        parent::__construct();
    }

    /**
     * True when a real account is the target — the only case with an owner to
     * warn. A stuffing run against an unregistered address has none.
     */
    public function hasKnownUser(): bool
    {
        return $this->userId !== null;
    }
}
