<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * An admin approved an organization's KYC — it is now operational.
 *
 * Drives the owner notification and the activity timeline. The status change
 * itself is a model diff captured by the Auditable trait (with the admin's
 * reason).
 */
final class OrganizationApproved extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly int $ownerId,
    ) {
        parent::__construct();
    }
}
