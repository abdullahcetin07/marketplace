<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A moderator refused a product proposal outright (§3.1).
 *
 * Distinct from `ProductRevisionRequested`: rejection is for a proposal that
 * cannot be accepted as conceived — a duplicate of an existing catalog entry, a
 * prohibited item — not for a fixable mistake.
 *
 * The reason travels on the event because the seller is told it, and because a
 * rejection with no stated cause is the fastest way to lose a merchant.
 *
 * @see docs/modules/Catalog.md §7
 */
final class ProductRejected extends BaseEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productUuid,
        public readonly ?string $proposedByOrgUuid,
        public readonly string $reason,
        public readonly ?int $moderatedBy = null,
    ) {
        parent::__construct();
    }
}
