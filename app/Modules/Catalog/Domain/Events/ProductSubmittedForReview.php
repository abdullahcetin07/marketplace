<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A seller submitted a product proposal; it is now in the Category Manager's
 * queue (§3.1/§5).
 *
 * This is the hand-off between the two humans in the loop, and the event a
 * future notification to the moderation team would hang on.
 *
 * @see docs/modules/Catalog.md §7
 */
final class ProductSubmittedForReview extends BaseEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productUuid,
        public readonly int $categoryId,
        public readonly ?string $proposedByOrgUuid,
    ) {
        parent::__construct();
    }
}
