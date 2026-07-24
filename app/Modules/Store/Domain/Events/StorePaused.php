<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A storefront was paused (vacation mode: Active → Paused). Seller-controlled and
 * self-reversible. Consumers hide it from selling surfaces while it is paused.
 *
 * @see docs/modules/Store.md §7
 */
final class StorePaused extends BaseEvent
{
    public function __construct(
        public readonly int $storeId,
        public readonly string $storeUuid,
        public readonly int $organizationId,
    ) {
        parent::__construct();
    }
}
