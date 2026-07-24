<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A legal company was registered (Pending).
 *
 * The forensic before/after is written by the Organization's Auditable trait;
 * this event is for the consumer modules — Activity (owner timeline) and, later,
 * an admin "new organization to review" signal.
 */
final class OrganizationCreated extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly int $ownerId,
    ) {
        parent::__construct();
    }
}
