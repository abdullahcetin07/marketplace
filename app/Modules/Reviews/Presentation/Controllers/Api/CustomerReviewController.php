<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Presentation\Controllers\Api;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Core\Domain\Contracts\StoreQueryContract;
use App\Core\Presentation\Controllers\BaseController;
use App\Models\User;
use App\Modules\Reviews\Application\Actions\CreateReviewAction;
use App\Modules\Reviews\Application\Actions\DeleteReviewAction;
use App\Modules\Reviews\Application\Services\ReviewEligibilityService;
use App\Modules\Reviews\Domain\Contracts\ReviewRepositoryContract;
use App\Modules\Reviews\Domain\DTOs\SubmitReviewDTO;
use App\Modules\Reviews\Domain\Exceptions\ReviewException;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Presentation\Requests\SubmitReviewRequest;
use App\Modules\Reviews\Presentation\Resources\MyReviewResource;
use App\Shared\Support\PublicKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The buyer's own four surfaces (Reviews.md §8).
 *
 * **`eligible` EXISTS TO DRAW A FORM, NOT TO AUTHORISE ONE.** It answers which
 * purchases the buyer may still write about, so the "Değerlendir" screen can name
 * WHICH one it is offering — a shopper who bought the same thing twice needs to
 * know which order this review is about. The action re-verifies every line
 * server-side; if these two ever disagreed, the action wins and the form was
 * simply wrong.
 *
 * **`store` RETURNS 201 WITH `pending_review`**, and that status is the point of
 * returning the resource at all: the UI must say "onay bekliyor" rather than
 * congratulating somebody on a review nobody can read yet (ADR-068).
 *
 * **THE AUTHOR'S NAME IS COMPUTED HERE AND STORED MASKED.** "Abdullah Ç." is
 * built from the authenticated actor — never from input, which would let a client
 * sign a review with somebody else's name — and written into the column already
 * masked, so no later surface can leak the full one.
 *
 * **THERE IS NO UPDATE.** A review is deleted and rewritten (Reviews.md §8): an
 * editable review lets somebody build credibility with five stars and then
 * rewrite it, with every reader who saw the first version having no way to know.
 *
 * @see docs/modules/Reviews.md §8
 */
final class CustomerReviewController extends BaseController
{
    public function __construct(
        private readonly ReviewEligibilityService $eligibility,
        private readonly ReviewRepositoryContract $reviews,
        private readonly CreateReviewAction $create,
        private readonly DeleteReviewAction $remove,
        private readonly CatalogBrowseContract $catalog,
        private readonly StoreQueryContract $stores,
    ) {}

    /**
     * GET /api/v1/reviews/eligible?product={idOrSlug}
     */
    public function eligible(Request $request): JsonResponse
    {
        $customer = $this->customer();
        $productUuid = $this->resolveProduct((string) $request->string('product'));

        $lines = $this->eligibility->eligibleLines(
            (int) $customer->getKey(),
            $customer->uuid,
            $productUuid,
        );

        // Every shop in one call — a buyer with four purchases of one product
        // should not cost four store reads.
        $stores = $this->stores->publicProfilesFor(array_values(array_unique(
            array_map(static fn (array $line): string => $line['store_uuid'], $lines),
        )));

        return $this->ok(array_map(
            static fn (array $line): array => [
                'order_line_uuid' => $line['order_line_uuid'],
                'seller' => [
                    'id' => $line['store_uuid'],
                    'name' => $stores[$line['store_uuid']]['name'] ?? null,
                ],
                'variant_label' => $line['variant_label'],
                // The title AS BOUGHT (ADR-053) — this screen is about a
                // purchase, so it names what actually arrived.
                'product_title' => $line['product_title'],
                'purchased_at' => $line['purchased_at'],
            ],
            $lines,
        ));
    }

    /**
     * POST /api/v1/reviews
     */
    public function store(SubmitReviewRequest $request): JsonResponse
    {
        $customer = $this->customer();
        $productUuid = $this->resolveProduct((string) $request->validated('product'));

        /** @var array<int, \Illuminate\Http\UploadedFile> $photos */
        $photos = $request->file('photos', []);

        $review = $this->create->run(
            new SubmitReviewDTO(
                orderLineUuid: $request->orderLineUuid(),
                rating: $request->rating(),
                body: $request->body(),
                productUuid: $productUuid,
                customerId: (int) $customer->getKey(),
                customerUuid: $customer->uuid,
                // MASKED AT THE SOURCE, from the actor — never from the payload.
                authorName: self::maskedName($customer),
            ),
            $photos,
        );

        return $this->created(new MyReviewResource($review, $this->titlesFor([$review])));
    }

    /**
     * GET /api/v1/reviews/mine
     */
    public function mine(): JsonResponse
    {
        $reviews = $this->reviews->forCustomer((int) $this->customer()->getKey());
        $titles = $this->titlesFor($reviews->all());

        return $this->ok($reviews
            ->map(static fn (Review $review): MyReviewResource => new MyReviewResource($review, $titles))
            ->all());
    }

    /**
     * DELETE /api/v1/reviews/{review}
     */
    public function destroy(string $review): JsonResponse
    {
        /*
        | BY SHAPE FIRST (ADR-059). `reviews.uuid` is a native uuid column on
        | PostgreSQL, so a malformed segment would be SQLSTATE[22P02] — a 500 on
        | a button the buyer taps. The trap, fourteenth watch.
        */
        if (! PublicKey::looksLikeUuid($review)) {
            throw new NotFoundHttpException;
        }

        $model = $this->reviews->findByUuid($review);

        if ($model === null) {
            throw new NotFoundHttpException;
        }

        // The POLICY decides ownership, not this controller and not the action:
        // one place for the rule (`ReviewPolicy::delete()`), which overrides the
        // base class precisely because a customer holds no `review.*` permission.
        $this->authorize('delete', $model);

        $this->remove->run($model);

        return $this->noContent();
    }

    /**
     * A slug or a uuid, resolved before anything touches a column.
     *
     * THE SAME 404 FOR EVERY MISS, exactly as the public endpoint gives: "no such
     * product", "not published" and "that is a category slug" are one answer, or
     * the differences map the catalogue for whoever is probing.
     */
    private function resolveProduct(string $idOrSlug): string
    {
        $uuid = $idOrSlug === '' ? null : $this->catalog->publishedProductUuidFor($idOrSlug);

        if ($uuid === null) {
            throw ReviewException::productNotFound($idOrSlug);
        }

        return $uuid;
    }

    /**
     * Current product titles for a set of reviews, in one call.
     *
     * @param array<int, Review> $reviews
     *
     * @return array<string, array{uuid: string, title: string, brand: string|null, category: string}>
     */
    private function titlesFor(array $reviews): array
    {
        if ($reviews === []) {
            return [];
        }

        return $this->catalog->productSummaries(array_values(array_unique(
            array_map(static fn (Review $review): string => $review->product_uuid, $reviews),
        )));
    }

    /**
     * "Abdullah Ç." — first name, last initial (Reviews.md §8).
     *
     * A SURNAME-LESS ACCOUNT IS JUST THE FIRST NAME, with no trailing dot to
     * suggest an initial was withheld. `User::initials()` next door answers a
     * different question (an avatar's "AY"), so this is not it reused.
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
