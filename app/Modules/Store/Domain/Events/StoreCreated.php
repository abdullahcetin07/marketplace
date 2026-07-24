<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A storefront was created from an approved Store Opening Request (ADR-032).
 *
 * THE REPORT-BACK SEAM. Store has done its part — the storefront exists in Draft.
 * Organization subscribes to this to fill `StoreOpeningRequest.created_store_uuid`
 * (the approved back-reference); later selling contexts (Product, Catalog,
 * Order) subscribe to know a store now exists. Store never calls those modules —
 * it announces the fact and stops.
 *
 * `openingRequestUuid` correlates this creation with the `StoreOpeningApproved`
 * that triggered it, so the two contexts' forensic trails stitch into one
 * incident.
 *
 * @see docs/modules/Store.md §4.2
 */
final class StoreCreated extends BaseEvent
{
    public function __construct(
        public readonly int $storeId,
        public readonly string $storeUuid,
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly string $openingRequestUuid,
        public readonly string $name,
        public readonly string $slug,
    ) {
        parent::__construct();
    }
}
