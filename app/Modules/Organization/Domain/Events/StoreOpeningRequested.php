<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A seller submitted a Store Opening Request into the admin queue (ADR-028).
 *
 * For the admin "new request to review" signal and the timeline. No store
 * exists yet — nothing is created until approval.
 */
final class StoreOpeningRequested extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly string $requestUuid,
        public readonly int $requestedBy,
    ) {
        parent::__construct();
    }
}
