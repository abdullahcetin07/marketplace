<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Presentation\Controllers\Api\Storefront;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Core\Domain\Contracts\StoreQueryContract;
use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Reviews\Domain\Contracts\ReviewRepositoryContract;
use App\Modules\Reviews\Domain\DTOs\ReviewListFilterDTO;
use App\Modules\Reviews\Domain\Exceptions\ReviewException;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Presentation\Resources\PublicReviewResource;
use App\Modules\Reviews\Presentation\Resources\ReviewSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A product's published reviews and its star rating (ADR-069).
 *
 * **THERE IS NO PRODUCT-PAGE ASSEMBLER TO CONTRIBUTE TO**, which is why this is
 * its own endpoint rather than a `StorefrontContributorContract` implementation.
 * That seam is store-page level (ADR-036); the product page is composed by the
 * Next.js storefront from several calls (content, offers, prices), and Reviews
 * adds one more exactly as Offer added the buy box.
 *
 * **THE LIST AND THE SUMMARY TRAVEL TOGETHER, and that is deliberate.** They are
 * one screen: the ★ block, the distribution bars and the cards under them are
 * read at the same moment, and splitting them would be a second round trip for
 * numbers computed from the same rows. `data` is the reviews so pagination means
 * what it says; the summary rides in `meta`, where it is not paginated and cannot
 * be mistaken for a page of results.
 *
 * **THE SUMMARY IS OF THE WHOLE PRODUCT, NOT OF THE FILTERED PAGE.** A shopper
 * who filters to one seller still sees the product's real average — the filter
 * narrows what they READ, not what the product IS. Recomputing it per filter
 * would make the star block jump every time somebody ticked a box.
 *
 * SLUG OR UUID, RESOLVED BEFORE ANYTHING TOUCHES A COLUMN (ADR-059) — the same
 * `CatalogBrowseContract` call the offers endpoint makes, and for the same
 * reason: a slug reaching a native `uuid` column is SQLSTATE[22P02], a 500 on a
 * public page. The platform has shipped that bug more than once.
 *
 * @see docs/modules/Reviews.md §7
 */
final class PublicProductReviewController extends BaseController
{
    public function __construct(
        private readonly ReviewRepositoryContract $reviews,
        private readonly CatalogBrowseContract $catalog,
        private readonly StoreQueryContract $stores,
    ) {}

    /**
     * GET /api/v1/products/{idOrSlug}/reviews
     */
    public function index(Request $request, string $product): JsonResponse
    {
        $productUuid = $this->catalog->publishedProductUuidFor($product);

        if ($productUuid === null) {
            // The same 404 "no such product", "not published" and "that is a
            // category slug" all get — the storefront's rule (ADR-034).
            throw ReviewException::productNotFound($product);
        }

        $page = $this->reviews->publishedForProduct($productUuid, $this->filter($request));
        $summary = $this->reviews->summaryForProduct($productUuid);

        /*
        | EVERY SHOP ON THE PAGE IN ONE CALL — the cards' sellers AND the
        | summary's breakdown, resolved together. One per row would be a query
        | per merchant on a public product page, which is the N+1 this contract
        | was batched to prevent.
        */
        $stores = $this->stores->publicProfilesFor(array_values(array_unique([
            ...array_map(static fn (Review $review): string => $review->store_uuid, $page->items()),
            ...array_map(static fn (array $seller): string => $seller['store_uuid'], $summary['sellers']),
        ])));

        return $this->ok(
            array_map(
                fn (Review $review): PublicReviewResource => new PublicReviewResource($review, $stores),
                $page->items(),
            ),
            null,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'summary' => (new ReviewSummaryResource($summary, $stores))->toArray(),
            ],
        );
    }

    /**
     * The three filters, read off the query string.
     *
     * **AN UNPARSEABLE FILTER IS AN ABSENT FILTER, NOT A 422.** `?rating=abc` on
     * a public page a crawler or a stale link can produce should show the
     * unfiltered reviews, not an error — and `?rating=9` matches nothing rather
     * than being rejected, because there is no rating 9 and saying so adds
     * nothing a shopper can use.
     */
    private function filter(Request $request): ReviewListFilterDTO
    {
        $rating = $request->integer('rating');

        return new ReviewListFilterDTO(
            sellerStoreUuid: $request->filled('seller') ? (string) $request->string('seller') : null,
            withImages: $request->boolean('with_images'),
            rating: $rating >= 1 && $rating <= 5 ? $rating : null,
            page: max(1, $request->integer('page', 1)),
            perPage: $this->perPage(default: 20),
        );
    }
}
