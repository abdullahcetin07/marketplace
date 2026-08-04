<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Controllers\Api\Storefront;

use App\Core\Domain\Contracts\OfferQueryContract;
use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Offer\Presentation\Requests\BuyBoxPricesRequest;
use App\Modules\Offer\Presentation\Resources\BuyBoxPricesResource;
use Illuminate\Http\JsonResponse;

/**
 * "₺X'den başlayan fiyatlarla" for a whole listing, in one call (ADR-058,
 * Storefront.md §1.2).
 *
 * THE OTHER HALF OF THE COMPOSED READ. Catalog's browse returns cards with no
 * price (ADR-037); this returns the price for the page's products. Two calls
 * instead of one join, because the two facts live in different bounded contexts
 * and a price column on the product is the thing that would stop one catalogue
 * entry being sold by many sellers.
 *
 * ONE CALL FOR THE PAGE, not one per card. A listing of 24 products asking
 * individually would be 24 requests on the platform's busiest screen, each
 * recomputing store liveness and availability; batched, they share the buy box's
 * per-instance memoisation.
 *
 * IT IS THE SAME WINNER THE PRODUCT PAGE SHOWS. Both go through `eligible()`
 * (ADR-045), so a shopper cannot see one price on a listing and another after
 * clicking — the failure that would make every price on the site untrustworthy.
 *
 * UNAUTHENTICATED, ON THE STOREFRONT THROTTLE, and CAPPED: uncapped, one request
 * could ask for the buy box of the whole catalogue, which is a denial-of-service
 * written as a feature.
 *
 * @see App\Modules\Catalog\Presentation\Controllers\Api\Storefront\PublicProductController
 * @see docs/modules/Storefront.md §1.2
 */
final class PublicBuyBoxPriceController extends BaseController
{
    public function __construct(
        private readonly OfferQueryContract $offers,
    ) {}

    /**
     * POST /api/v1/offers/prices
     */
    public function index(BuyBoxPricesRequest $request): JsonResponse
    {
        $prices = $this->offers->buyBoxPricesFor($request->productUuids());

        return $this->ok(new BuyBoxPricesResource($prices, $this->decimalsByCurrency($prices)));
    }

    /**
     * How many decimal places each currency in the result renders to.
     *
     * READ FROM LOCALIZATION rather than assumed to be 2: a zero-decimal currency
     * (JPY) formatted as "1299.00" is wrong, and the rule already lives on the
     * currency row (003 §16). One query for the whole page, not one per row.
     *
     * @param array<string, array{price_minor: int, currency_code: string, in_stock: bool}> $prices
     *
     * @return array<string, int>
     */
    private function decimalsByCurrency(array $prices): array
    {
        $codes = array_values(array_unique(array_map(
            static fn (array $price): string => $price['currency_code'],
            $prices,
        )));

        if ($codes === []) {
            return [];
        }

        /** @var array<string, int> $decimals */
        $decimals = Currency::query()
            ->whereIn('code', $codes)
            ->pluck('decimal_places', 'code')
            ->map(static fn (mixed $places): int => (int) $places)
            ->all();

        return $decimals;
    }
}
