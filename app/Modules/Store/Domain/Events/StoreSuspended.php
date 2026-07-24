<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * An admin froze a storefront (any live state → Suspended) for a policy breach.
 * Only an admin reinstates it. Carries the actor and reason for the forensic
 * trail (ADR-027) and for consumers that must react to enforcement.
 *
 * @see docs/modules/Store.md §7
 */
final class StoreSuspended extends BaseEvent
{
    public function __construct(
        public readonly int $storeId,
        public readonly string $storeUuid,
        public readonly int $organizationId,
        public readonly ?int $suspendedBy,
        public readonly ?string $reason,
    ) {
        parent::__construct();
    }
}
