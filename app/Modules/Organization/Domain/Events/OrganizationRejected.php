<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * An admin rejected an organization's KYC.
 *
 * Carries the reason so the owner's notification can explain it. The reason is
 * also on the audit entry (the trait) — this copy is for delivery, not the
 * forensic record.
 */
final class OrganizationRejected extends BaseEvent
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
