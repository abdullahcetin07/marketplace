<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\OfferQueryContract;
use App\Modules\Questions\Application\Actions\AnswerQuestionAction;
use App\Modules\Questions\Application\Actions\AskQuestionAction;
use App\Modules\Questions\Application\Actions\HideQuestionAction;
use App\Modules\Questions\Application\Actions\UnhideQuestionAction;
use App\Modules\Questions\Domain\DTOs\AnswerQuestionDTO;
use App\Modules\Questions\Domain\DTOs\AskQuestionDTO;
use App\Modules\Questions\Domain\DTOs\HideQuestionDTO;
use App\Modules\Questions\Domain\Enums\QuestionStatus;
use App\Modules\Questions\Domain\Events\QuestionAnswered;
use App\Modules\Questions\Domain\Events\QuestionAsked;
use App\Modules\Questions\Domain\Exceptions\QuestionException;
use App\Modules\Questions\Domain\Models\Question;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Who gets asked, and who may answer (ADR-070/071)
|--------------------------------------------------------------------------
|
| **THE TARGET IS THE SECURITY BOUNDARY HERE**, the way the purchase gate is in
| Reviews. A client sends `{product, body}` and no seller; the action reads the
| buy-box winner and freezes it. If that ever came from input, a shopper could
| aim a hostile question at a merchant who never sold the thing.
|
| Offer is FAKED — Questions may not import it, so the module's own tests exercise
| it through the Core contract exactly as production does.
|
*/

/**
 * A stand-in for Offer that answers with whatever winner a test hands it.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @param array<string, mixed>|null $featured
 */
function fakeOfferPort(?array $featured): void
{
    app()->bind(OfferQueryContract::class, fn (): OfferQueryContract => new class($featured) implements OfferQueryContract
    {
        /** @param array<string, mixed>|null $featured */
        public function __construct(private readonly ?array $featured) {}

        /** @return array<string, mixed>|null */
        public function featuredOfferForProduct(string $productUuid): ?array
        {
            return $this->featured;
        }

        public function offerExists(string $offerUuid): bool
        {
            return false;
        }

        /** @return array<int, array<string, mixed>> */
        public function activeOffersForProduct(string $productUuid): array
        {
            return [];
        }

        /** @return array<int, array<string, mixed>> */
        public function activeOffersForVariant(string $variantUuid): array
        {
            return [];
        }

        /** @return array<int, array<string, mixed>> */
        public function offersForStore(string $storeUuid): array
        {
            return [];
        }

        /** @return array<string, mixed>|null */
        public function activeOfferByUuid(string $offerUuid): ?array
        {
            return null;
        }

        /**
         * @param array<int, string> $productUuids
         *
         * @return array<int, string>
         */
        public function sellableProductUuids(array $productUuids = []): array
        {
            return [];
        }

        /**
         * @param array<int, string> $productUuids
         *
         * @return array<string, array<string, mixed>>
         */
        public function buyBoxPricesFor(array $productUuids): array
        {
            return [];
        }
    });
}

/**
 * @return array<string, mixed>
 */
function buyBoxWinner(string $storeUuid = 'kazanan-magaza', string $orgUuid = 'kazanan-org'): array
{
    return [
        'uuid' => (string) Str::uuid(),
        'store_uuid' => $storeUuid,
        'selling_org_uuid' => $orgUuid,
        'price_minor' => 12_000,
    ];
}

function askDto(string $productUuid, string $body = 'Bu ürün kaç beden büyük geliyor?'): AskQuestionDTO
{
    return new AskQuestionDTO(
        productUuid: $productUuid,
        body: $body,
        customerId: 7,
        customerUuid: 'soran',
        askerName: 'Ayşe Y.',
    );
}

beforeEach(function (): void {
    $this->product = (string) Str::uuid();
});

it('aims the question at the buy-box winner and freezes it there', function (): void {
    fakeOfferPort(buyBoxWinner('magaza-A', 'org-A'));
    Event::fake([QuestionAsked::class]);

    $question = app(AskQuestionAction::class)->run(askDto($this->product));

    /*
     * **THE TARGET CAME FROM THE OFFER, NOT THE REQUEST** (ADR-070). There is no
     * field on `AskQuestionDTO` that could have carried it — which is what makes
     * it impossible to aim a question at a shop that is not selling the product.
     */
    expect($question->store_uuid)->toBe('magaza-A')
        ->and($question->selling_org_uuid)->toBe('org-A')
        // Born private to the seller, the admin and the asker.
        ->and($question->status)->toBe(QuestionStatus::Pending)
        ->and($question->isPublic())->toBeFalse();

    Event::assertDispatched(QuestionAsked::class);
});

it('does not re-aim a past question when the buy box changes', function (): void {
    fakeOfferPort(buyBoxWinner('magaza-A', 'org-A'));

    $question = app(AskQuestionAction::class)->run(askDto($this->product));

    // Somebody undercuts, or the winner runs out of stock.
    fakeOfferPort(buyBoxWinner('magaza-B', 'org-B'));

    /*
     * **THE SNAPSHOT IS THE POINT.** The question stays addressed to whoever the
     * shopper was actually looking at, so the answer on the page stays
     * attributable to whoever gave it — and a merchant is never handed a question
     * asked of somebody else.
     */
    expect($question->fresh()->store_uuid)->toBe('magaza-A');

    // A NEW question goes to the new winner.
    expect(app(AskQuestionAction::class)->run(askDto($this->product))->store_uuid)->toBe('magaza-B');
});

it('refuses to ask when nobody is selling the product', function (): void {
    fakeOfferPort(null);

    /*
     * A CLEAN REFUSAL, NOT AN ERROR (422). Everything going out of stock is an
     * ordinary state a product page is in — the shopper is told there is no
     * seller right now, not that something broke.
     */
    expect(fn () => app(AskQuestionAction::class)->run(askDto($this->product)))
        ->toThrow(QuestionException::class);

    expect(Question::query()->count())->toBe(0);
});

it('asks with no purchase anywhere in sight', function (): void {
    fakeOfferPort(buyBoxWinner());

    /*
     * **THE POSITIVE ASSERTION THAT SEPARATES THIS MODULE FROM REVIEWS.** There
     * is no order, no delivered line and no gate: a question is asked to decide
     * WHETHER to buy, so requiring a purchase would defeat the feature. Being a
     * signed-in customer is the entire bar (ADR-070).
     */
    expect(app(AskQuestionAction::class)->run(askDto($this->product))->exists)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The seller's answer publishes it
|--------------------------------------------------------------------------
*/

it('publishes the pair the moment the seller answers', function (): void {
    Event::fake([QuestionAnswered::class]);
    $question = Question::factory()->pending()->create();

    app(AnswerQuestionAction::class)->run(
        $question,
        new AnswerQuestionDTO(answerBody: 'Normal kalıp.', answeredBy: 42),
    );

    /*
     * NO MODERATOR IN THE PATH — the mirror of Reviews' pre-moderation. The
     * question waited for the merchant it was aimed at, not for staff.
     */
    expect($question->fresh()->status)->toBe(QuestionStatus::Answered)
        ->and($question->fresh()->answer_body)->toBe('Normal kalıp.')
        ->and($question->fresh()->answered_by)->toBe(42)
        ->and($question->fresh()->answered_at)->not->toBeNull()
        ->and($question->fresh()->isPublic())->toBeTrue();

    Event::assertDispatched(QuestionAnswered::class);
});

it('refuses a second answer rather than overwriting the first', function (): void {
    $question = Question::factory()->answered('İlk cevap.')->create();

    /*
     * TWO COLLEAGUES SHARE A SELLER PANEL. The second one's answer silently
     * replacing the first's — with the shopper already looking at the first — is
     * the failure this refusal prevents. There is no edit path either: correcting
     * a published answer is not a v1 operation, and refusing is the honest way to
     * say so.
     */
    expect(fn () => app(AnswerQuestionAction::class)->run(
        $question,
        new AnswerQuestionDTO(answerBody: 'İkinci cevap.', answeredBy: 43),
    ))->toThrow(QuestionException::class);

    expect($question->fresh()->answer_body)->toBe('İlk cevap.');
});

/*
|--------------------------------------------------------------------------
| The admin's only lever
|--------------------------------------------------------------------------
*/

it('hides either state and restores whatever it was', function (): void {
    $pending = Question::factory()->pending()->create();
    $answered = Question::factory()->answered()->create();

    foreach ([$pending, $answered] as $question) {
        app(HideQuestionAction::class)->run($question, new HideQuestionDTO(hiddenBy: 3, reason: 'Küfür içeriyor'));

        expect($question->fresh()->isHidden())->toBeTrue()
            ->and($question->fresh()->hidden_reason)->toBe('Küfür içeriyor')
            ->and($question->fresh()->isPublic())->toBeFalse();
    }

    /*
     * **HIDING NEVER TOUCHED THE STATUS**, which is the whole argument for the
     * flag — so un-hiding restores each to exactly what it was, with nothing to
     * reconstruct.
     */
    expect($pending->fresh()->status)->toBe(QuestionStatus::Pending)
        ->and($answered->fresh()->status)->toBe(QuestionStatus::Answered);

    app(UnhideQuestionAction::class)->run($pending);
    app(UnhideQuestionAction::class)->run($answered);

    expect($pending->fresh()->isPublic())->toBeFalse()
        ->and($answered->fresh()->isPublic())->toBeTrue()
        // The stale reason is cleared with it — left behind, it reads to the next
        // admin as though the question were still hidden for that.
        ->and($answered->fresh()->hidden_reason)->toBeNull()
        ->and($answered->fresh()->hidden_by)->toBeNull();
});
