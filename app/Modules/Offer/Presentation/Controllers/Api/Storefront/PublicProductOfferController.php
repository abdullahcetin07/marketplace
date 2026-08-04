<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Controllers\Api\Storefront;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Core\Domain\Contracts\OfferQueryContract;
use App\Core\Domain\Contracts\StoreQueryContract;
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
 * THREE CONTRACTS, NO IMPORTS, and that is the composition ADR-058 asks for made
 * literal: Catalog says what the thing is, Offer says what it costs, Store says
 * who is selling it. Offer holds store uuids and nothing else — putting a
 * merchant's NAME in front of a shopper is a Store fact, asked for through
 * `StoreQueryContract` rather than by reaching into Store's tables (ADR-033).
 *
 * ADDRESSED BY SLUG OR UUID (ADR-059), resolved through `CatalogBrowseContract`
 * before any query runs. Offer holds no slug registry — that is Catalog's, and
 * this module imports nothing — so the resolution is one more question asked of
 * the contract it already depends on.
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
        private readonly StoreQueryContract $stores,
        private readonly CurrencyRepositoryContract $currencies,
    ) {}

    /**
     * GET /api/v1/products/{idOrSlug}/offers
     */
    public function show(string $product): JsonResponse
    {
        /*
        | A SLUG OR A UUID, RESOLVED BEFORE ANYTHING TOUCHES A COLUMN (ADR-059).
        |
        | This is a PUBLIC storefront URL and the flat scheme means the segment is
        | usually a slug — so passing it straight into a `product_uuid` comparison
        | was `SQLSTATE[22P02]` on PostgreSQL: a 500 on the buy box, not a 404.
        | The platform's FOURTH occurrence of that one shape.
        |
        | Offer may not import Catalog's slug registry (`LayeringTest`), so it
        | asks the Core browse contract — exactly as it already asks that contract
        | for the product's title two lines below.
        */
        $productUuid = $this->catalog->publishedProductUuidFor($product);

        $summary = $productUuid === null
            ? null
            : ($this->catalog->productSummaries([$productUuid])[$productUuid] ?? null);

        if ($summary === null) {
            // Same 404 for "no such product", "not published" and "not a product
            // slug at all", so existence never leaks — the storefront's own rule
            // (ADR-034).
            throw OfferException::productNotPublished($product)
                ->withStatus(404);
        }

        $offers = $this->offers->activeOffersForProduct($productUuid);

        return $this->ok(new PublicProductOffersResource(
            $summary,
            $offers,
            // Every seller on the page in ONE call — one per row would be a query
            // per merchant on the platform's busiest page.
            $this->stores->publicProfilesFor(array_values(array_unique(array_map(
                static fn (array $offer): string => (string) $offer['store_uuid'],
                $offers,
            )))),
            (int) $this->currencies->default()->decimal_places,
        ));
    }
}
