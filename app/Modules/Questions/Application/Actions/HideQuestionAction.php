<?php

declare(strict_types=1);

namespace App\Modules\Questions\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Questions\Domain\DTOs\HideQuestionDTO;
use App\Modules\Questions\Domain\Models\Question;

/**
 * An admin takes it down — the platform's only lever (ADR-071).
 *
 * **IT WORKS ON EITHER STATE, WHICH IS WHY HIDING IS A FLAG.** A pending question
 * can be abusive before any merchant has to read it, and an answered pair can
 * turn out to be unacceptable after the fact. One mechanism covers both because
 * it does not touch the status.
 *
 * **IT IS NOT A DELETE.** The row stays, the reason is recorded, and un-hiding
 * restores exactly what was there — a takedown somebody can reverse is the right
 * shape for a judgement call made in seconds on somebody else's words.
 *
 * **THE ADMIN DOES NOT ANSWER** (ADR-071), and there is deliberately no action in
 * this module that would let them: the platform speaking in a merchant's place is
 * a promise the merchant did not make.
 *
 * IDEMPOTENT BY OVERWRITE, not by a guard: hiding an already-hidden question
 * re-stamps who and why, which is the honest record of a second admin agreeing.
 *
 * @see docs/modules/Questions.md §7
 */
final class HideQuestionAction extends BaseAction
{
    public function handle(mixed ...$arguments): Question
    {
        /** @var Question $question */
        $question = $arguments[0];
        /** @var HideQuestionDTO $dto */
        $dto = $arguments[1];

        $question->forceFill([
            'hidden_at' => now(),
            'hidden_by' => $dto->hiddenBy,
            'hidden_reason' => $dto->reason,
        ])->save();

        return $question;
    }
}
