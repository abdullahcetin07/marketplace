<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A member was removed from an organization.
 *
 * Never the Owner — the Owner cannot be removed (ADR-029); ownership leaves only
 * by transfer.
 */
final class OrganizationMemberRemoved extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly int $userId,
    ) {
        parent::__construct();
    }
}
