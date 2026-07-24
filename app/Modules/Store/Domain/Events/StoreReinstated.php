<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * An admin lifted a suspension (Suspended → its prior state). The mirror of
 * StoreSuspended; carries the actor for the forensic trail (ADR-027).
 *
 * @see docs/modules/Store.md §7
 */
final class StoreReinstated extends BaseEvent
{
    public function __construct(
        public readonly int $storeId,
        public readonly string $storeUuid,
        public readonly int $organizationId,
        public readonly ?int $reinstatedBy,
    ) {
        parent::__construct();
    }
}
