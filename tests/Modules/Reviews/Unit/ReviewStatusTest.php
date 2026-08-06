<?php

declare(strict_types=1);

use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Models\Review;

/*
|--------------------------------------------------------------------------
| What a review's status can be, and what it cannot (ADR-068)
|--------------------------------------------------------------------------
|
| The enum is small and one absence in it is a decision: there is no
| `NeedsRevision`, because a buyer does not iterate on an opinion the way a
| seller iterates on a listing. Asserting the case list is how that stays a
| decision rather than something somebody adds one afternoon.
|
*/

it('has exactly the three states a review can be in', function (): void {
    /*
     * NO `NeedsRevision` — Catalog's product moderation has one because a seller
     * wants their listing published and will fix it; handing a REVIEW back with
     * notes would be the platform coaching somebody on what to think about a
     * product they bought.
     *
     * NO `Hidden`/`Disputed` either: the seller has no lever over a review
     * (ADR-068), and the absence of a state is the strongest way to say so.
     */
    expect(array_map(fn (ReviewStatus $s): string => $s->value, ReviewStatus::cases()))
        ->toBe(['pending_review', 'published', 'rejected']);
});

it('shows a stranger published reviews and nothing else', function (): void {
    expect(ReviewStatus::Published->isPublished())->toBeTrue()
        ->and(ReviewStatus::PendingReview->isPublished())->toBeFalse()
        // A REJECTED REVIEW IS NEVER PUBLIC AND NEVER COUNTED — not in the list,
        // not in the average, not in the distribution.
        ->and(ReviewStatus::Rejected->isPublished())->toBeFalse();
});

it('knows which reviews still owe a moderator an answer', function (): void {
    expect(ReviewStatus::PendingReview->awaitsModeration())->toBeTrue()
        ->and(ReviewStatus::Published->awaitsModeration())->toBeFalse()
        ->and(ReviewStatus::Rejected->awaitsModeration())->toBeFalse();

    // The queue's two verdicts — and both terminal states offer none, which is
    // what makes re-deciding an answered review a refusal rather than a no-op.
    expect(ReviewStatus::PendingReview->moderationOutcomes())
        ->toBe([ReviewStatus::Published, ReviewStatus::Rejected])
        ->and(ReviewStatus::Published->moderationOutcomes())->toBe([])
        ->and(ReviewStatus::Rejected->moderationOutcomes())->toBe([]);
});

it('gives every case a colour and a translated label', function (): void {
    foreach (ReviewStatus::cases() as $status) {
        expect($status->color())->toBeIn(['warning', 'success', 'danger'])
            ->and($status->label())->not->toBe('');
    }
});

it('casts a review the way the surfaces read it', function (): void {
    $casts = (new Review)->getCasts();

    /*
     * THE STATUS IS THE ENUM, not a string, because every guard in the module
     * asks it a question (`isPublished()`, `awaitsModeration()`) rather than
     * comparing text — a string cast would make each of those a `===` somebody
     * can typo.
     *
     * `rating` IS AN INTEGER AND NOT A DECIMAL: it is a count of stars, and the
     * only decimal in this module is the AVERAGE, which is computed on read and
     * crosses as a string.
     */
    expect($casts['status'])->toBe(ReviewStatus::class)
        ->and($casts['rating'])->toBe('integer')
        ->and($casts['has_photos'])->toBe('boolean')
        // Immutable: a moderation stamp is a fact about a past decision, and
        // nothing should be able to nudge it by mutating the instance.
        ->and($casts['moderated_at'])->toBe('immutable_datetime');
});
