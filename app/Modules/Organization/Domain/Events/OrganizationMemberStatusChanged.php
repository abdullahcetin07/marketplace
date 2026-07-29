<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A membership was frozen or thawed (§2.2).
 *
 * DISTINCT FROM `OrganizationMemberRemoved`, and the distinction is the whole
 * reason this exists. Removal is an ending: the row is soft-deleted and the
 * person is gone. Deactivation is a pause — an employee on leave, a contractor
 * between engagements, an account under internal review — and the membership
 * survives with its role intact, so reinstating is one click rather than a
 * re-invitation and a fresh acceptance.
 *
 * A consumer that only cares "can this person act right now" reads
 * `status`; one that cares whether they are still on the team needs the
 * difference, which is why collapsing the two into a removal event would be
 * lossy.
 *
 * Carries both sides so a listener need not query for what changed.
 */
final class OrganizationMemberStatusChanged extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly int $userId,
        public readonly string $previousStatus,
        public readonly string $status,
    ) {
        parent::__construct();
    }
}
