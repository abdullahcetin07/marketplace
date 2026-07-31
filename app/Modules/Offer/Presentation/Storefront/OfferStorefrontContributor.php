<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Storefront;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Core\Domain\Contracts\OfferQueryContract;
use App\Core\Domain\Storefront\StorefrontContext;
use App\Core\Domain\Storefront\StorefrontContributorContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Core\Presentation\Support\MoneyString;

/**
 * What a store SELLS, on its public storefront page (ADR-046 — the contributor
 * ADR-041 deferred).
 *
 * THE PIECE THAT MAKES A STOREFRONT A SHOP. Store shipped the storefront core —
 * identity, branding, SEO, contact — and Catalog deliberately registered no
 * contributor, because "a store's products" means its offers and those did not
 * exist (ADR-041). They do now, so this is that section, and the dependency
 * points the way ADR-036 designed it: Offer registers itself with the Core
 * `StorefrontRegistry`, and Store composes it without knowing Offer exists.
 *
 * `key()` IS A PUBLIC PROMISE. It becomes a key in the storefront's `extensions`
 * object, so deployed clients read it by name — changing it later breaks them
 * silently. `products` rather than `offers` because that is what a shopper is
 * looking at; the offer is how the platform models it, not what the page shows.
 *
 * IT MUST BE CHEAP AND MUST NOT THROW (the contract's own rule): it runs on
 * every public storefront request, and a broken section here must degrade to an
 * empty list rather than take down a store's page. The assembler catches
 * throwables anyway; not relying on that is the difference between a contract
 * and a hope.
 *
 * A CAP, NOT A PAGINATOR. A storefront page shows a store's offers — it is not
 * the place to browse ten thousand of them, and an uncapped list would put an
 * unbounded payload on the platform's hottest anonymous route. When a real
 * "browse this store" surface is needed it gets its own paginated endpoint;
 * silently truncating without saying so is the failure mode this comment exists
 * to prevent, so the count is reported alongside.
 *
 * @see docs/modules/Offer.md §6
 */
final class OfferStorefrontContributor implements StorefrontContributorContract
{
    /**
     * How many listings a storefront page carries. @see the class docblock.
     */
    private const int MAX_LISTINGS = 24;

    public function __construct(
        private readonly OfferQueryContract $offers,
        private readonly CatalogBrowseContract $catalog,
        private readonly CurrencyRepositoryContract $currencies,
    ) {}

    public function key(): string
    {
        return 'products';
    }

    /**
     * @return array<string, mixed>
     */
    public function contribute(StorefrontContext $context): array
    {
        $offers = $this->offers->offersForStore($context->storeUuid);

        if ($offers === []) {
            // A live store with nothing listed is an ordinary state, not an
            // error: it was approved before its first product went up.
            return ['items' => [], 'total' => 0];
        }

        $shown = array_slice($offers, 0, self::MAX_LISTINGS);

        // One batched catalog call for the whole page rather than one per row.
        $summaries = $this->catalog->productSummaries(
            array_values(array_unique(array_column($shown, 'product_uuid'))),
        );

        $decimals = (int) $this->currencies->default()->decimal_places;

        $items = [];

        foreach ($shown as $offer) {
            $summary = $summaries[$offer['product_uuid']] ?? null;

            if ($summary === null) {
                // The product was unpublished after the offer was made. Its
                // offers are already paused by the cascade (§3.5), so this is
                // rare — but a storefront must not render a title the catalog
                // no longer stands behind.
                continue;
            }

            $items[] = [
                'id' => $offer['uuid'],
                'product_id' => $offer['product_uuid'],
                'variant_id' => $offer['variant_uuid'],
                'title' => $summary['title'],
                'brand' => $summary['brand'],
                'price' => MoneyString::from((int) $offer['price_minor'], $decimals),
                'list_price' => $offer['list_price_minor'] === null
                    ? null
                    : MoneyString::from((int) $offer['list_price_minor'], $decimals),
                'currency' => $offer['currency_code'],
                'in_stock' => (bool) $offer['in_stock'],
            ];
        }

        return [
            'items' => $items,
            // The store's true listing count, so a client can tell "these are
            // all of them" from "these are the first 24".
            'total' => count($offers),
        ];
    }
}
