<?php

declare(strict_types=1);

namespace App\Modules\Questions\Presentation\Controllers\Api;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Core\Presentation\Controllers\BaseController;
use App\Models\User;
use App\Modules\Questions\Application\Actions\AskQuestionAction;
use App\Modules\Questions\Application\Actions\DeleteQuestionAction;
use App\Modules\Questions\Domain\Contracts\QuestionRepositoryContract;
use App\Modules\Questions\Domain\DTOs\AskQuestionDTO;
use App\Modules\Questions\Domain\Exceptions\QuestionException;
use App\Modules\Questions\Domain\Models\Question;
use App\Modules\Questions\Presentation\Requests\SubmitQuestionRequest;
use App\Modules\Questions\Presentation\Resources\MyQuestionResource;
use App\Shared\Support\PublicKey;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The shopper's own three surfaces (Questions.md §8).
 *
 * **THERE IS NO "ELIGIBLE" ENDPOINT HERE, AND THAT ABSENCE IS THE MODULE.**
 * Reviews needs one because a review is bound to a delivered purchase and the
 * form has to name which. A question needs no purchase at all (ADR-070) — the
 * shopper is standing on the product page, so `POST /questions` with the product
 * is the entire flow.
 *
 * **THE 201 SAYS `pending`**, which is why the resource comes back: the UI must
 * say "sorunuz satıcıya iletildi" rather than showing it as though it were live.
 * Only the merchant's answer publishes it.
 *
 * **THE ASKER'S NAME IS COMPUTED HERE AND STORED MASKED.** "Abdullah Ç." is built
 * from the authenticated actor — never from input, which would let a client sign
 * a question with somebody else's name — and written already masked, so no later
 * surface can leak the full one.
 *
 * **THERE IS NO UPDATE.** A question is deleted and asked again: editing one a
 * merchant has already answered would leave their reply attached to a different
 * question than the one they read.
 *
 * @see docs/modules/Questions.md §8
 */
final class CustomerQuestionController extends BaseController
{
    public function __construct(
        private readonly QuestionRepositoryContract $questions,
        private readonly AskQuestionAction $ask,
        private readonly DeleteQuestionAction $remove,
        private readonly CatalogBrowseContract $catalog,
    ) {}

    /**
     * POST /api/v1/questions
     */
    public function store(SubmitQuestionRequest $request): JsonResponse
    {
        $customer = $this->customer();

        $question = $this->ask->run(new AskQuestionDTO(
            productUuid: $this->resolveProduct($request->productIdOrSlug()),
            body: $request->body(),
            customerId: (int) $customer->getKey(),
            customerUuid: $customer->uuid,
            // MASKED AT THE SOURCE, from the actor — never from the payload.
            askerName: self::maskedName($customer),
        ));

        return $this->created(new MyQuestionResource($question, $this->titlesFor([$question])));
    }

    /**
     * GET /api/v1/questions/mine
     */
    public function mine(): JsonResponse
    {
        $questions = $this->questions->forCustomer((int) $this->customer()->getKey());
        $titles = $this->titlesFor($questions->all());

        return $this->ok($questions
            ->map(static fn (Question $question): MyQuestionResource => new MyQuestionResource($question, $titles))
            ->all());
    }

    /**
     * DELETE /api/v1/questions/{question}
     */
    public function destroy(string $question): JsonResponse
    {
        /*
        | BY SHAPE FIRST (ADR-059). `questions.uuid` is a native uuid column on
        | PostgreSQL, so a malformed segment would be SQLSTATE[22P02] — a 500 on
        | a button the shopper taps. The trap, fifteenth watch.
        */
        if (! PublicKey::looksLikeUuid($question)) {
            throw new NotFoundHttpException;
        }

        $model = $this->questions->findByUuid($question);

        if ($model === null) {
            throw new NotFoundHttpException;
        }

        // The POLICY owns the rule (`QuestionPolicy::delete()`), which overrides
        // the base class because a customer holds no `question.*` permission.
        $this->authorize('delete', $model);

        $this->remove->run($model);

        return $this->noContent();
    }

    /**
     * A slug or a uuid, resolved before anything touches a column.
     *
     * THE SAME 404 FOR EVERY MISS, as every storefront surface gives: "no such
     * product", "not published" and "that is a category slug" are one answer.
     */
    private function resolveProduct(string $idOrSlug): string
    {
        $uuid = $this->catalog->publishedProductUuidFor($idOrSlug);

        if ($uuid === null) {
            throw QuestionException::productNotFound($idOrSlug);
        }

        return $uuid;
    }

    /**
     * Current product titles for a set of questions, in one call.
     *
     * @param array<int, Question> $questions
     *
     * @return array<string, array{uuid: string, title: string, brand: string|null, category: string}>
     */
    private function titlesFor(array $questions): array
    {
        if ($questions === []) {
            return [];
        }

        return $this->catalog->productSummaries(array_values(array_unique(
            array_map(static fn (Question $question): string => $question->product_uuid, $questions),
        )));
    }

    /**
     * "Abdullah Ç." — first name, last initial (§3).
     *
     * A SURNAME-LESS ACCOUNT IS JUST THE FIRST NAME, with no trailing dot to
     * suggest an initial was withheld. The same rule Reviews applies to a review's
     * author, deliberately duplicated rather than shared: the two modules import
     * nothing from each other, and a masking rule is four lines.
     */
    private static function maskedName(User $user): string
    {
        $last = $user->last_name;

        if ($last === null || trim($last) === '') {
            return $user->first_name;
        }

        return $user->first_name.' '.mb_strtoupper(mb_substr(trim($last), 0, 1)).'.';
    }

    private function customer(): User
    {
        $actor = current_actor();

        if (! $actor instanceof User) {
            throw new NotFoundHttpException;
        }

        return $actor;
    }
}
