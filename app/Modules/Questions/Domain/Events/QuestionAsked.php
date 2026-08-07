<?php

declare(strict_types=1);

namespace App\Modules\Questions\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A shopper asked; only the target seller can see it (ADR-070).
 *
 * **A HOOK WITH NO LISTENER, DELIBERATELY** (§11). The obvious consumer is a
 * "yeni bir sorunuz var" notice to the merchant, and no notification ships in v1
 * — but the event fires now, so that listener is a new class rather than a change
 * to this module.
 *
 * IT CARRIES THE TARGET STORE, because that is who the future notice goes to and
 * the one fact a consumer could not re-derive without reading the row.
 */
final class QuestionAsked extends BaseEvent
{
    public function __construct(
        public readonly int $questionId,
        public readonly string $questionUuid,
        public readonly string $productUuid,
        public readonly string $storeUuid,
        public readonly int $customerId,
    ) {
        parent::__construct();
    }
}
