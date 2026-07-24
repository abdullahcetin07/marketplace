<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A user became a member of an organization (on registration as Owner, or by
 * accepting an invitation in Phase 3).
 *
 * `role` is the enum VALUE (a string), so a consumer — Activity, a welcome
 * notification — never imports Organization's enum to read it.
 */
final class OrganizationMemberJoined extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly int $userId,
        public readonly string $role,
    ) {
        parent::__construct();
    }
}
