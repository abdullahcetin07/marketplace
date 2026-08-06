<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Where a review is in moderation (ADR-068, Reviews.md §6).
 *
 * **A REVIEW IS BORN INVISIBLE.** The owner's hard requirement is that nothing
 * appears until it is approved, so `PendingReview` is the only state a buyer's
 * submission can start in and the public surfaces read `Published` alone. A
 * `Rejected` review never appears and is never counted in a product's average.
 *
 * **THREE CASES, AND THE ABSENT FOURTH IS THE DECISION.** Catalog's product
 * moderation has a `NeedsRevision` — a seller iterates on a listing until it is
 * right, because the listing is their shopfront and they want it published. A
 * buyer does not iterate on an opinion. Handing one back with notes would be the
 * platform coaching somebody on what to think about a product they bought, so a
 * review is good enough to publish or it is not.
 *
 * THE SELLER HAS NO CASE HERE EITHER. There is no `Hidden`, no `Disputed` and no
 * `AwaitingSellerResponse`: the party a review judges gets no lever over it
 * (ADR-068), and the absence of a state is the strongest way to say so.
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 *
 * @see docs/modules/Reviews.md §6
 */
enum ReviewStatus: string
{
    use HasEnumHelpers;

    case PendingReview = 'pending_review';
    case Published = 'published';
    case Rejected = 'rejected';

    /**
     * Whether this review may be read by a stranger.
     *
     * ASKED IN ONE PLACE, because the public list, the batch ratings endpoint and
     * the summary must all agree about it — a product whose average counts a
     * review its own list does not show is a product nobody can explain.
     */
    public function isPublished(): bool
    {
        return $this === self::Published;
    }

    /**
     * Whether a moderator still owes this review an answer — what the queue
     * counts and what both verdict actions guard on.
     */
    public function awaitsModeration(): bool
    {
        return $this === self::PendingReview;
    }

    /**
     * The two answers a moderator may give.
     *
     * A TERMINAL PAIR: neither `Published` nor `Rejected` goes anywhere
     * afterwards. A published review that turns out to be unacceptable is
     * DELETED rather than un-published — Reviews keeps no audit trail of its own
     * (that is Audit's job), so a tombstone status would be a record nothing
     * reads.
     *
     * @return array<int, self>
     */
    public function moderationOutcomes(): array
    {
        return $this->awaitsModeration() ? [self::Published, self::Rejected] : [];
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingReview => 'warning',
            self::Published => 'success',
            self::Rejected => 'danger',
        };
    }

    public function label(): string
    {
        return __("enums.ReviewStatus.{$this->value}");
    }
}
