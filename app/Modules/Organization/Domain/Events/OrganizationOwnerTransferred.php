<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * Ownership of an organization was transferred (ADR-029).
 *
 * The atomic swap already happened; this announces it. Both the previous and
 * new owner are notified — losing or gaining ownership of a company is exactly
 * the kind of change the affected people must hear about.
 */
final class OrganizationOwnerTransferred extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly int $previousOwnerId,
        public readonly int $newOwnerId,
    ) {
        parent::__construct();
    }
}
