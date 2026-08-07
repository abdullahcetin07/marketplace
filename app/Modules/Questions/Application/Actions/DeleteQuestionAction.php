<?php

declare(strict_types=1);

namespace App\Modules\Questions\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Questions\Domain\Contracts\QuestionRepositoryContract;
use App\Modules\Questions\Domain\Models\Question;

/**
 * The asker takes their question back (Questions.md §8).
 *
 * **ANSWERED OR NOT.** A shopper may remove a question that a merchant has
 * already answered, and the answer goes with it — correct, because the answer
 * only ever existed as a reply to that question, and an orphan would publish half
 * an exchange. The merchant loses a public answer they wrote; that is the cost of
 * letting somebody unpublish their own words, and it is the right way round.
 *
 * A HARD DELETE. A question is not an audit record — the append-only rule belongs
 * to Audit and Activity — and unlike Reviews there is no unique constraint to
 * free, so nothing depends on the row's absence beyond the row being absent.
 *
 * **OWNERSHIP IS THE POLICY'S JOB.** It is checked at the controller, where the
 * actor is known; an action re-deriving it would be a second home for the rule
 * and the two would eventually disagree.
 *
 * @see docs/modules/Questions.md §8
 */
final class DeleteQuestionAction extends BaseAction
{
    public function __construct(private readonly QuestionRepositoryContract $questions) {}

    public function handle(mixed ...$arguments): mixed
    {
        /** @var Question $question */
        $question = $arguments[0];

        $this->questions->delete($question);

        return null;
    }
}
