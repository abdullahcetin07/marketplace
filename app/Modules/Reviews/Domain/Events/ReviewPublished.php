<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A moderator approved it; it is public from this moment (ADR-068).
 *
 * **NOTHING RECOMPUTES AN AVERAGE ON THIS.** The product's rating summary is
 * computed on read (ADR-069), so there is no counter to bump and no cache to
 * bust — publishing a review changes the next read and nothing else. If
 * aggregates ever get hot enough to denormalise, THIS is the event that would
 * maintain the counter, which is why it carries the product uuid.
 *
 * IDS AND UUIDS, NEVER THE MODEL — @see `ReviewSubmitted`.
 */
final class ReviewPublished extends BaseEvent
{
    public function __construct(
        public readonly int $reviewId,
        public readonly string $reviewUuid,
        public readonly string $productUuid,
        public readonly int $moderatedBy,
    ) {
        parent::__construct();
    }
}
