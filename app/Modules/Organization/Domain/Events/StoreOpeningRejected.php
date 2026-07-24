<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * An admin rejected a Store Opening Request (ADR-028).
 *
 * Carries the admin notes for the seller's notification. No store is created,
 * and no slot is consumed.
 */
final class StoreOpeningRejected extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly string $requestUuid,
        public readonly int $requestedBy,
        public readonly ?string $adminNotes = null,
    ) {
        parent::__construct();
    }
}
