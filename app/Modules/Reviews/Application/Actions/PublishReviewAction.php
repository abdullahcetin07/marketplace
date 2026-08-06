<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Reviews\Domain\DTOs\ReviewModerationDTO;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Events\ReviewPublished;
use App\Modules\Reviews\Domain\Exceptions\ReviewException;
use App\Modules\Reviews\Domain\Models\Review;

/**
 * A moderator approves it; it becomes public from this moment (ADR-068).
 *
 * **NOTHING IS RECOMPUTED AND NOTHING IS INVALIDATED.** The product's average and
 * distribution are computed on read (ADR-069), so publishing a review changes the
 * next read and nothing else — there is no counter to bump and no cache to bust.
 * That is the whole payoff of not storing the aggregate.
 *
 * **THE GUARD IS `awaitsModeration()`, NOT "IS IT PUBLISHED"**, so a second click
 * on an already-REJECTED review is refused too. The two verdicts do opposite
 * things: silently re-publishing something a colleague removed is the failure
 * this refusal exists to prevent, and it is the more likely one on a queue two
 * people are working.
 *
 * @see docs/modules/Reviews.md §6
 */
final class PublishReviewAction extends BaseAction
{
    private ?ReviewPublished $published = null;

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
            'status' => ReviewStatus::Published,
            'moderated_at' => now(),
            'moderated_by' => $dto->moderatedBy,
        ])->save();

        $this->published = new ReviewPublished(
            reviewId: (int) $review->getKey(),
            reviewUuid: $review->uuid,
            productUuid: $review->product_uuid,
            moderatedBy: $dto->moderatedBy,
        );

        return $review;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->published !== null) {
            event($this->published);
        }
    }
}
