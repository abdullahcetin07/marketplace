<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * An admin approved a Store Opening Request (ADR-028).
 *
 * THIS IS THE SEAM TO THE STORE MODULE. Organization has done its part — the
 * request is approved and a slot is consumed. The **Store module** (future)
 * subscribes to this event and creates the actual Store, then reports the new
 * store's UUID back so the request can record `created_store_uuid`. Organization
 * never creates a Store itself.
 *
 * Until the Store module exists, this event fires into no listener — by design
 * (an event with no subscriber is not an error). The payload carries everything
 * the Store module needs so no back-query into Organization is required.
 */
final class StoreOpeningApproved extends BaseEvent
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationUuid,
        public readonly string $requestUuid,
        public readonly int $requestedBy,
        public readonly string $storeName,
        public readonly string $slug,
        public readonly ?int $categoryId = null,
    ) {
        parent::__construct();
    }
}
