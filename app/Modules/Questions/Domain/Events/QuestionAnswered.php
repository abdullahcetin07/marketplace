<?php

declare(strict_types=1);

namespace App\Modules\Questions\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * The seller answered; the pair is public from this moment (ADR-070).
 *
 * **THIS IS THE PUBLICATION EVENT**, which in Reviews would be a moderator's
 * approval. Nothing recomputes on it — visibility is `Answered && hidden_at IS
 * NULL`, computed on read — so it exists for the future "cevabınız yayınlandı"
 * notice to the asker, which is why it carries the customer.
 *
 * IDS AND UUIDS, NEVER THE MODEL: a queued listener deserialising one would
 * re-read a row that may have been hidden or deleted since.
 */
final class QuestionAnswered extends BaseEvent
{
    public function __construct(
        public readonly int $questionId,
        public readonly string $questionUuid,
        public readonly string $productUuid,
        public readonly string $storeUuid,
        public readonly int $customerId,
        public readonly int $answeredBy,
    ) {
        parent::__construct();
    }
}
