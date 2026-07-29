<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Controllers\Api\Storefront;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Core\Domain\Contracts\OfferQueryContract;
use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Offer\Domain\Exceptions\OfferException;
use App\Modules\Offer\Presentation\Resources\PublicProductOffersResource;
use Illuminate\Http\JsonResponse;

/**
 * "Who sells this, and for how much" — the buyer-facing product page's data
 * (§5, ADR-034 shape).
 *
 * UNAUTHENTICATED and uuid-resolved, on the storefront throttle: this is
 * anonymous browsing traffic, not API traffic, and it is the first buyer-facing
 * surface the platform has ever had.
 *
 * IT OWNS NO RULE. The buy box is computed by `OfferQueryContract` — the same
 * answer the storefront contributor and any future Order validation get, which
 * is exactly why the winner is not recomputed here. The product summary comes
 * from `CatalogBrowseContract`, so a product that has since been unpublished
 * disappears from this surface without Offer knowing anything about moderation.
 *
 * A PRODUCT NOBODY PUBLISHES AND A PRODUCT NOBODY SELLS ARE DIFFERENT ANSWERS.
 * The first 404s — it is not public, and saying so would leak that a draft
 * exists. The second returns the product with `featured: null`: it is a real
 * page a buyer may legitimately land on from search or a bookmark, and telling
 * them "currently unavailable" is the honest response.
 *
 * @see docs/modules/Offer.md §5
 */
final class PublicProductOfferController extends BaseController
{
    public function __construct(
        private readonly OfferQueryContract $offers,
        private readonly CatalogBrowseContract $catalog,
        private readonly CurrencyRepositoryContract $currencies,
    ) {}

    /**
     * GET /api/v1/products/{product}/offers
     */
    public function show(string $product): JsonResponse
    {
        $summary = $this->catalog->productSummaries([$product])[$product] ?? null;

        if ($summary === null) {
            // Same 404 for "no such product" and "not published", so existence
            // never leaks — the storefront's own rule (ADR-034).
            throw OfferException::productNotPublished($product)
                ->withStatus(404);
        }

        return $this->ok(new PublicProductOffersResource(
            $summary,
            $this->offers->activeOffersForProduct($product),
            (int) $this->currencies->default()->decimal_places,
        ));
    }
}
