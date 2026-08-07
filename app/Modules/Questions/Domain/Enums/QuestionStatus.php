<?php

declare(strict_types=1);

namespace App\Modules\Questions\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Whether the seller has answered yet (ADR-070, Questions.md §5).
 *
 * **TWO CASES, AND HIDING IS NOT ONE OF THEM.** An admin may hide a PENDING
 * question — an abusive one, before any seller has to read it — or an ANSWERED
 * one, and may un-hide either. A third status would have to answer "hidden from
 * what?" and would lose the state it came from; a nullable `hidden_at` alongside
 * these two says both things at once and reverses cleanly.
 *
 * **THE SELLER'S ANSWER IS WHAT PUBLISHES IT**, which is this module's whole
 * difference from Reviews. There is no `PendingReview` here and no moderator in
 * the path: a question waits for the merchant it was aimed at, not for staff.
 * Moderation is reactive (ADR-070).
 *
 * NO `Declined`. A seller who does not want to answer leaves it pending, and an
 * admin hides an abusive one — a refusal button would publish the fact that a
 * merchant refused, which is a worse answer than silence (§11).
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 *
 * @see docs/modules/Questions.md §5
 */
enum QuestionStatus: string
{
    use HasEnumHelpers;

    case Pending = 'pending';
    case Answered = 'answered';

    /**
     * Still waiting on the seller — what their panel's badge counts.
     */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /**
     * **NOT THE SAME QUESTION AS "IS IT PUBLIC".** An answered question that an
     * admin has hidden is answered and invisible, so visibility is
     * `isAnswered() && hidden_at === null` and lives on the model, where both
     * halves are in scope.
     */
    public function isAnswered(): bool
    {
        return $this === self::Answered;
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Answered => 'success',
        };
    }

    public function label(): string
    {
        return __("enums.QuestionStatus.{$this->value}");
    }
}
