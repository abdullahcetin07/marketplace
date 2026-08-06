<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A buyer wrote one; nobody can see it yet (ADR-068).
 *
 * **IT IS A HOOK WITH NO LISTENER, AND THAT IS DELIBERATE** (Reviews.md §11). The
 * obvious consumer is a "N değerlendirme onay bekliyor" notification to
 * moderators, and no notification ships in v1 — but the event fires now, so that
 * listener is a new class rather than a change to this module when somebody
 * wants it.
 *
 * IDS AND UUIDS, NEVER THE MODEL. A queued listener deserialising a model would
 * re-read a row that may have been moderated or deleted since; the payload is the
 * facts as they were.
 */
final class ReviewSubmitted extends BaseEvent
{
    public function __construct(
        public readonly int $reviewId,
        public readonly string $reviewUuid,
        public readonly string $productUuid,
        public readonly int $customerId,
    ) {
        parent::__construct();
    }
}
