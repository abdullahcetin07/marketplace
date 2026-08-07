<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Presentation\Controllers\Api\Storefront;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Reviews\Domain\Contracts\ReviewRepositoryContract;
use App\Modules\Reviews\Presentation\Requests\BatchRatingsRequest;
use Illuminate\Http\JsonResponse;

/**
 * A whole grid's stars in one call (ADR-069) — the mirror of `POST /offers/prices`.
 *
 * **A POST THAT READS, AND THE REASON IS THE URL LENGTH.** Forty uuids is well
 * past what belongs in a query string, and this is the same shape the price
 * endpoint already established for the same page — a listing card needs a price
 * badge and a star badge, and both arrive in one round trip each rather than one
 * per card.
 *
 * **AN UNREVIEWED PRODUCT IS ABSENT FROM THE MAP.** Not `{"average":"0.0"}`: a
 * card handed a zero renders "★ 0.0", which a shopper reads as "rated badly"
 * rather than "not rated yet" — the one wrong answer this shape can give, and it
 * is avoided by not answering. The client's own "no rating" branch is the
 * correct rendering.
 *
 * @see docs/modules/Reviews.md §7
 */
final class ProductRatingsController extends BaseController
{
    public function __construct(private readonly ReviewRepositoryContract $reviews) {}

    /**
     * POST /api/v1/products/ratings
     */
    public function batch(BatchRatingsRequest $request): JsonResponse
    {
        return $this->ok([
            'ratings' => $this->reviews->summariesForProducts($request->productUuids()),
        ]);
    }
}
