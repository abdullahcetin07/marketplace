<?php

declare(strict_types=1);

namespace App\Modules\Questions\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\OfferQueryContract;
use App\Modules\Questions\Domain\Contracts\QuestionRepositoryContract;
use App\Modules\Questions\Domain\DTOs\AskQuestionDTO;
use App\Modules\Questions\Domain\Enums\QuestionStatus;
use App\Modules\Questions\Domain\Events\QuestionAsked;
use App\Modules\Questions\Domain\Exceptions\QuestionException;
use App\Modules\Questions\Domain\Models\Question;

/**
 * "Satıcıya sor" — a shopper asks, and the server decides who is being asked
 * (ADR-070).
 *
 * **THE TARGET IS DERIVED HERE AND FROZEN HERE.** The request carries `{product,
 * body}` and no seller; this action reads the buy-box winner from
 * `OfferQueryContract` and snapshots its store onto the row. Two things follow
 * from that, and both are the point:
 *
 *   NOBODY CAN AIM A QUESTION AT A SHOP THAT IS NOT SELLING THE PRODUCT, because
 *   there is no field on `AskQuestionDTO` that could carry one — the same shape
 *   Reviews uses for its seller tag, and for the same reason: a merchant must
 *   not be made to answer for goods that were never theirs.
 *
 *   A LATER BUY-BOX CHANGE NEVER RE-AIMS A PAST QUESTION. The winner moves when
 *   somebody undercuts or runs out of stock; the question stays addressed to
 *   whoever the shopper was actually looking at, and the answer on the page stays
 *   attributable to whoever gave it.
 *
 * **NO PURCHASE GATE, AND THAT IS THE WHOLE DIFFERENCE FROM REVIEWS.** A review
 * reports an experience, so it is gated on a delivered line. A question is asked
 * to decide WHETHER to buy — gating it would defeat the feature. Being a
 * signed-in customer is the entire bar (ADR-070).
 *
 * **NO SELLABLE OFFER IS A REFUSAL, NOT AN ERROR** (422). A product nobody is
 * selling is an ordinary state a page can be in — everything went out of stock,
 * or every offer was suspended — so the shopper is told there is no seller right
 * now, not that something broke.
 *
 * @see docs/modules/Questions.md §4
 */
final class AskQuestionAction extends BaseAction
{
    private ?QuestionAsked $asked = null;

    public function __construct(
        private readonly OfferQueryContract $offers,
        private readonly QuestionRepositoryContract $questions,
    ) {}

    /**
     * `handle(AskQuestionDTO $dto)` — variadic because `BaseAction` is.
     */
    public function handle(mixed ...$arguments): Question
    {
        /** @var AskQuestionDTO $dto */
        $dto = $arguments[0];

        $featured = $this->offers->featuredOfferForProduct($dto->productUuid);

        if ($featured === null) {
            throw QuestionException::noSeller();
        }

        $question = $this->questions->create([
            'product_uuid' => $dto->productUuid,
            'customer_id' => $dto->customerId,
            'customer_uuid' => $dto->customerUuid,
            'asker_name' => $dto->askerName,
            // AUTHORITATIVE, FROM THE BUY BOX (ADR-070) — not from the DTO, which
            // deliberately cannot carry them.
            'store_uuid' => (string) $featured['store_uuid'],
            'selling_org_uuid' => (string) $featured['selling_org_uuid'],
            'body' => $dto->body,
            'status' => QuestionStatus::Pending,
        ]);

        $this->asked = new QuestionAsked(
            questionId: (int) $question->getKey(),
            questionUuid: $question->uuid,
            productUuid: $question->product_uuid,
            storeUuid: $question->store_uuid,
            customerId: $question->customer_id,
        );

        return $question;
    }

    /**
     * Dispatched AFTER COMMIT — a future "yeni bir sorunuz var" notice must not
     * reach a merchant about a question a later failure rolls back. No listener
     * ships in v1 (§11); the event fires now so that one is a new class rather
     * than a change here.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->asked !== null) {
            event($this->asked);
        }
    }
}
