<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A product was delisted (§3.1/§3.5) — the terminal state.
 *
 * Search drops the document on this (§10). The row itself is NEVER hard-deleted:
 * Offers will reference it, and an order history that points at a vanished
 * product is unreadable.
 *
 * @see docs/modules/Catalog.md §7, §10
 */
final class ProductArchived extends BaseEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productUuid,
        public readonly ?string $proposedByOrgUuid,
    ) {
        parent::__construct();
    }
}
