<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A suspended organization was restored to operation by an admin.
 *
 * The mirror of OrganizationSuspended; returns the company to its prior status
 * (Approved). Recorded on the timeline; the diff is the trait's.
 */
final class OrganizationRestored extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly int $ownerId,
    ) {
        parent::__construct();
    }
}
