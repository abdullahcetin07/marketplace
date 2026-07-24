<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * An admin suspended an organization — members cannot act until it is restored.
 *
 * Carries the reason for the owner's notification. High-signal: suspension is
 * how the platform reacts to a policy breach or dispute.
 */
final class OrganizationSuspended extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly int $ownerId,
        public readonly ?string $reason = null,
    ) {
        parent::__construct();
    }
}
