<?php

declare(strict_types=1);

namespace App\Modules\Questions\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Questions\Domain\Models\Question;

/**
 * An admin puts it back (ADR-071).
 *
 * **IT RESTORES WHATEVER THE QUESTION ALREADY WAS**, and needs to know nothing
 * about that: a hidden pending question becomes pending again, a hidden answered
 * one becomes public again. Clearing three columns is the whole operation
 * precisely because hiding never touched the status — which is the argument for
 * the flag, demonstrated.
 *
 * THE REASON IS CLEARED WITH IT. A stale "küfür içeriyor" sitting on a question
 * somebody decided was fine reads, to the next admin, as though it were still
 * hidden for that.
 *
 * @see docs/modules/Questions.md §7
 */
final class UnhideQuestionAction extends BaseAction
{
    public function handle(mixed ...$arguments): Question
    {
        /** @var Question $question */
        $question = $arguments[0];

        $question->forceFill([
            'hidden_at' => null,
            'hidden_by' => null,
            'hidden_reason' => null,
        ])->save();

        return $question;
    }
}
