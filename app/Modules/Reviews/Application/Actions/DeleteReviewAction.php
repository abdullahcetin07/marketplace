<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Reviews\Domain\Contracts\ReviewRepositoryContract;
use App\Modules\Reviews\Domain\Models\Review;

/**
 * The buyer takes it back (Reviews.md §8).
 *
 * **DELETE, NOT EDIT, AND THAT IS THE WHOLE POLICY.** A review may be removed
 * and written again from the still-eligible line; it may not be quietly changed.
 * An edited review would let somebody build credibility with a five-star opinion
 * and then rewrite it, with every reader who saw the first version having no way
 * to know.
 *
 * **A HARD DELETE, because the freed `order_line_uuid` is the point.** A soft
 * delete would leave a ghost row colliding with the unique index, so the buyer
 * who deleted a mistaken review could never write the correct one. Reviews keeps
 * no audit trail of its own either — the append-only rule belongs to Audit and
 * Activity, and a review is not evidence in a dispute the way a ledger entry is.
 *
 * **OWNERSHIP IS THE POLICY'S JOB, NOT THIS ACTION'S.** It is checked at the
 * controller, where the actor is known; an action that re-derived it would be a
 * second place for the rule to live and the two would eventually disagree.
 *
 * @see docs/modules/Reviews.md §8
 */
final class DeleteReviewAction extends BaseAction
{
    public function __construct(private readonly ReviewRepositoryContract $reviews) {}

    public function handle(mixed ...$arguments): mixed
    {
        /** @var Review $review */
        $review = $arguments[0];

        $this->reviews->delete($review);

        return null;
    }
}
