<?php

declare(strict_types=1);

namespace App\Modules\Questions\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Questions\Domain\DTOs\AnswerQuestionDTO;
use App\Modules\Questions\Domain\Enums\QuestionStatus;
use App\Modules\Questions\Domain\Events\QuestionAnswered;
use App\Modules\Questions\Domain\Exceptions\QuestionException;
use App\Modules\Questions\Domain\Models\Question;

/**
 * The seller answers — and that is what publishes the pair (ADR-070/071).
 *
 * **THERE IS NO MODERATOR IN THIS PATH**, which is the mirror of Reviews'
 * pre-moderation. A question waits for the merchant it was aimed at, not for
 * staff; the moment they answer, both halves go public. An admin's only lever
 * comes AFTERWARDS and is a hide (ADR-071).
 *
 * **THE GUARD IS `isPending()`, SO A SECOND ANSWER IS A REFUSAL.** Two colleagues
 * share a seller panel, and the second one's answer silently replacing the
 * first's — with the shopper already looking at the first — is the failure this
 * prevents. There is no edit path either: correcting a published answer is not a
 * v1 operation, and the honest way to say so is that this action refuses.
 *
 * **WHO ANSWERED IS STORED AND NEVER PUBLISHED.** `answered_by` is a seller USER
 * id, because a Seller Employee may answer (§6) and "which colleague said this"
 * is what a merchant asks when a customer disputes it. The shopper sees the
 * SHOP's answer, not an individual's.
 *
 * @see docs/modules/Questions.md §6
 */
final class AnswerQuestionAction extends BaseAction
{
    private ?QuestionAnswered $answered = null;

    public function handle(mixed ...$arguments): Question
    {
        /** @var Question $question */
        $question = $arguments[0];
        /** @var AnswerQuestionDTO $dto */
        $dto = $arguments[1];

        if (! $question->isPending()) {
            throw QuestionException::notPending($question->uuid);
        }

        $question->forceFill([
            'status' => QuestionStatus::Answered,
            'answer_body' => $dto->answerBody,
            'answered_at' => now(),
            'answered_by' => $dto->answeredBy,
        ])->save();

        $this->answered = new QuestionAnswered(
            questionId: (int) $question->getKey(),
            questionUuid: $question->uuid,
            productUuid: $question->product_uuid,
            storeUuid: $question->store_uuid,
            customerId: $question->customer_id,
            answeredBy: $dto->answeredBy,
        );

        return $question;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->answered !== null) {
            event($this->answered);
        }
    }
}
