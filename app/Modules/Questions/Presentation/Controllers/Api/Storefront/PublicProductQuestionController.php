<?php

declare(strict_types=1);

namespace App\Modules\Questions\Presentation\Controllers\Api\Storefront;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Core\Domain\Contracts\StoreQueryContract;
use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Questions\Domain\Contracts\QuestionRepositoryContract;
use App\Modules\Questions\Domain\DTOs\QuestionListFilterDTO;
use App\Modules\Questions\Domain\Exceptions\QuestionException;
use App\Modules\Questions\Domain\Models\Question;
use App\Modules\Questions\Presentation\Resources\PublicQuestionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A product's answered Q&A (ADR-070, Questions.md §8).
 *
 * **NO SUMMARY BLOCK, AND THE ABSENCE IS THE SHAPE OF THE MODULE.** Reviews'
 * equivalent endpoint carries an average, a distribution and a count in `meta`
 * because a rating rolls up. A question does not: there is nothing to average, so
 * `data` is the Q&A and the only `meta` is pagination.
 *
 * **AN UNANSWERED QUESTION IS NEVER HERE.** The repository's `public()` scope is
 * the whole gate — answered AND un-hidden — and this controller cannot ask for
 * anything else because no method offers it. That matters more than a filter
 * convenience: publishing one early would put a shopper's words on a page before
 * the merchant they were aimed at had seen them.
 *
 * SLUG OR UUID, RESOLVED BEFORE ANYTHING TOUCHES A COLUMN (ADR-059) — the same
 * `CatalogBrowseContract` call the offers and reviews endpoints make, because a
 * slug reaching a native `uuid` column is SQLSTATE[22P02], a 500 on a public page.
 *
 * @see docs/modules/Questions.md §8
 */
final class PublicProductQuestionController extends BaseController
{
    public function __construct(
        private readonly QuestionRepositoryContract $questions,
        private readonly CatalogBrowseContract $catalog,
        private readonly StoreQueryContract $stores,
    ) {}

    /**
     * GET /api/v1/products/{idOrSlug}/questions
     */
    public function index(Request $request, string $product): JsonResponse
    {
        $productUuid = $this->catalog->publishedProductUuidFor($product);

        if ($productUuid === null) {
            // The same 404 "no such product", "not published" and "that is a
            // category slug" all get — the storefront's rule (ADR-034).
            throw QuestionException::productNotFound($product);
        }

        $page = $this->questions->publicForProduct($productUuid, new QuestionListFilterDTO(
            sellerStoreUuid: $request->filled('seller') ? (string) $request->string('seller') : null,
            page: max(1, $request->integer('page', 1)),
            perPage: $this->perPage(default: 20),
        ));

        // Every shop on the page in ONE call — one per row would be a query per
        // merchant on a public product page.
        $stores = $this->stores->publicProfilesFor(array_values(array_unique(
            array_map(static fn (Question $question): string => $question->store_uuid, $page->items()),
        )));

        return $this->paginated($page, array_map(
            fn (Question $question): PublicQuestionResource => new PublicQuestionResource($question, $stores),
            $page->items(),
        ));
    }
}
