<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A member's role within an organization was changed by an owner/manager.
 *
 * The Owner role never travels here — it is reached only by ownership transfer
 * (ADR-029), which raises OrganizationOwnerTransferred instead.
 */
final class OrganizationMemberRoleChanged extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly int $userId,
        public readonly string $previousRole,
        public readonly string $newRole,
    ) {
        parent::__construct();
    }
}
