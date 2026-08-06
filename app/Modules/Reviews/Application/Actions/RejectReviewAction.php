<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Reviews\Domain\DTOs\ReviewModerationDTO;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Events\ReviewRejected;
use App\Modules\Reviews\Domain\Exceptions\ReviewException;
use App\Modules\Reviews\Domain\Models\Review;

/**
 * A moderator refuses it; it will never be public (ADR-068).
 *
 * **THE ROW STAYS.** A rejected review is not deleted: the buyer must still see
 * it in "değerlendirmelerim" — otherwise their submission vanished and they
 * write it again — and its `order_line_uuid` must stay taken, or the same
 * refused text comes straight back through the eligibility screen.
 *
 * **THE REASON IS FOR THE RECORD, NOT FOR THE BUYER** (Reviews.md §6). It is
 * required by the panel and shown to nobody outside it: telling somebody why
 * their opinion was refused invites an argument the platform has no process for,
 * while a second moderator or a support agent taking the complaint needs to know
 * what the first one saw.
 *
 * @see docs/modules/Reviews.md §6
 */
final class RejectReviewAction extends BaseAction
{
    private ?ReviewRejected $rejected = null;

    public function handle(mixed ...$arguments): Review
    {
        /** @var Review $review */
        $review = $arguments[0];
        /** @var ReviewModerationDTO $dto */
        $dto = $arguments[1];

        if (! $review->awaitsModeration()) {
            throw ReviewException::notPending($review->uuid);
        }

        $review->forceFill([
            'status' => ReviewStatus::Rejected,
            'moderation_reason' => $dto->reason,
            'moderated_at' => now(),
            'moderated_by' => $dto->moderatedBy,
        ])->save();

        $this->rejected = new ReviewRejected(
            reviewId: (int) $review->getKey(),
            reviewUuid: $review->uuid,
            productUuid: $review->product_uuid,
            moderatedBy: $dto->moderatedBy,
            reason: $dto->reason,
        );

        return $review;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->rejected !== null) {
            event($this->rejected);
        }
    }
}
