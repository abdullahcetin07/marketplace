<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A membership invitation was issued.
 *
 * Carries NO token (ADR-025/031) — the raw token travels only in the
 * notification's email. This event is for the timeline and any "you invited X"
 * confirmation, never for delivering the credential.
 */
final class OrganizationMemberInvited extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly string $email,
        public readonly string $role,
        public readonly int $invitedBy,
    ) {
        parent::__construct();
    }
}
