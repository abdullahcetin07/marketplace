<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A moderator refused it; it will never be public (ADR-068).
 *
 * IT CARRIES THE REASON, and the buyer is still not shown it (Reviews.md §6).
 * The payload is for the internal record — a support agent taking the complaint,
 * or a second moderator wondering what the first one saw — which is exactly why
 * the reason travels on the event rather than only sitting in a column somebody
 * has to go and look up.
 *
 * IDS AND UUIDS, NEVER THE MODEL — @see `ReviewSubmitted`.
 */
final class ReviewRejected extends BaseEvent
{
    public function __construct(
        public readonly int $reviewId,
        public readonly string $reviewUuid,
        public readonly string $productUuid,
        public readonly int $moderatedBy,
        public readonly ?string $reason = null,
    ) {
        parent::__construct();
    }
}
