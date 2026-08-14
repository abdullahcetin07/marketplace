<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Queries;

use App\Core\Domain\Contracts\OfferQueryContract;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Shared\Support\PublicKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The BUYER's product listing (ADR-058, Storefront.md §1.1).
 *
 * THE COMPOSITION THIS WHOLE SPRINT IS ABOUT. Catalog owns what a product IS;
 * Offer owns whether anybody sells it and for how much (ADR-037). A shopper's
 * listing needs both — a card with no price is useless, and a card for something
 * nobody stocks is worse than useless, because clicking it lands on "unavailable".
 *
 * So this asks `OfferQueryContract` which products are SELLABLE and narrows its
 * own query to those. Catalog gains no price column and imports no Offer; the
 * boundary is a Core contract, exactly as `CatalogBoundaryTest` requires.
 *
 * WHY IT ASKS FOR THE WHOLE SELLABLE SET BEFORE PAGINATING, which is the one
 * decision here worth arguing about. Filtering a page AFTER fetching it gives
 * pages of variable size and a wrong total — "1 234 results" that yields 12 rows
 * on page 1 and 20 on page 2 is a listing a buyer cannot trust and a client cannot
 * paginate. The sellable fact is therefore carried ON the product, as an indexed
 * column (ADR-079), rather than resolved into a `whereIn` per request.
 *
 * THE SCALING WALL THIS CLASS PREDICTED, AND THEN HIT (ADR-079). The old note here
 * said a `whereIn` of a hundred thousand uuids would eventually be the slow part of
 * the page, and that denormalising was deliberately not built yet because it would
 * be a second source of truth for something computed correctly. It arrived earlier
 * than that: 7,025 uuids per request, built from 9,510 round trips, 22 seconds, and
 * 504s that reached shoppers as "Application error".
 *
 * So it is built. `products.is_sellable` is indexed alongside `status`, kept current
 * by Offer's and Inventory's events and rebuilt by `catalog:refresh-sellability` —
 * and the second-source-of-truth objection is answered by the rebuild rather than by
 * avoidance: the offers, the stores and the ledger stay authoritative, this is a
 * cache of what they say, and anything that drifts is repaired on a schedule.
 *
 * PRICE SORTING IS DRIVEN BY OFFER, NOT BY CATALOG, for the same reason: the
 * price lives in another context, so ordering by it means ordering the uuid list
 * before the content is fetched, not adding an `orderBy` to a Catalog query that
 * has no price to order by. The content read then preserves that order explicitly.
 *
 * A CATALOG-INTERNAL CLASS, deliberately not a Core contract: nothing outside
 * Catalog reads it. Publishing a port with one caller inside the same module would
 * be ceremony.
 *
 * @see docs/modules/Storefront.md §1.1
 * @see App\Modules\Catalog\Presentation\Controllers\Api\Storefront\PublicProductController
 */
final class PublicProductBrowse
{
    public const string SORT_NEWEST = 'newest';

    public const string SORT_PRICE_ASC = 'price_asc';

    public const string SORT_PRICE_DESC = 'price_desc';

    private const int MAX_PER_PAGE = 48;

    /** A listing offers a shortlist, not a directory: past ~40 nobody scrolls. */
    private const int MAX_FACET_BRANDS = 40;

    private const int FACET_TTL = 300;

    public function __construct(
        private readonly OfferQueryContract $offers,
    ) {}

    /**
     * A page of buyer-facing product cards.
     *
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     last_page: int,
     *     facets: array{brands: array<int, array<string, mixed>>, price: array{min: string|null, max: string|null}},
     * }
     */
    public function cards(
        string $query = '',
        ?string $categoryUuid = null,
        ?string $brandUuid = null,
        string $sort = self::SORT_NEWEST,
        int $page = 1,
        int $perPage = 24,
        ?int $priceMinMinor = null,
        ?int $priceMaxMinor = null,
    ): array {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $page = max(1, $page);

        /*
        | **FACETS ARE COMPUTED WITHOUT THE BRAND AND PRICE THE SHOPPER PICKED**
        | (ADR-080). A facet that hid its own siblings would be a one-way door:
        | pick Maruderm and the brand list collapses to Maruderm, so the only way
        | back is the browser's. The category and the search term DO scope them —
        | those are the query, not a facet choice within it.
        */
        $facets = $this->facets($query, $categoryUuid);

        $withinPrice = $this->uuidsWithinPrice($query, $categoryUuid, $priceMinMinor, $priceMaxMinor);

        if ($withinPrice !== null && $withinPrice === []) {
            return $this->empty($page, $perPage) + ['facets' => $facets];
        }

        /*
        | **THE SELLABLE WALL IS NOW A COLUMN** (ADR-079). It used to collect every
        | sellable product uuid and hand them to a `whereIn`: 7,025 of them on the
        | live catalogue, on every request, after 9,510 round trips to build the
        | list — 22 seconds, and 504s that reached shoppers as "Application error".
        |
        | `products.is_sellable` is the same fact, denormalised and indexed
        | alongside `status`, kept current by Offer's and Inventory's events and
        | rebuilt by `catalog:refresh-sellability`. Nothing below can widen past it,
        | so an unsold or unpublished product still cannot reach a buyer whatever
        | else the filters say.
        */
        $result = $sort === self::SORT_NEWEST
            ? $this->byNewest($query, $categoryUuid, $brandUuid, $page, $perPage, $withinPrice)
            : $this->byPrice($query, $categoryUuid, $brandUuid, $sort, $page, $perPage, $withinPrice);

        return $result + ['facets' => $facets];
    }

    /**
     * Ranked uuids → buyer-facing cards, in the order they were ranked (ADR-077/078).
     *
     * **THE RANKING COMES FROM ANOTHER MODULE AND THE CARD COMES FROM THIS ONE.**
     * Order knows what sold and Reviews knows what was reviewed; neither may build
     * a product card, and Catalog may not read either's tables. Each strip is one
     * Core call for the order, then this for the substance.
     *
     * **`whereIn` DOES NOT PRESERVE ORDER** — SQL `IN` is a set — so the rank is
     * reapplied afterwards from the position map. Sorting in PHP rather than with
     * a database `CASE` is deliberate at this size: a strip is twelve rows, and the
     * ordering rule then reads the same on every driver.
     *
     * **THE SELLABLE WALL STILL APPLIES.** A best-seller that has gone out of stock
     * or been unpublished is a dead card — the buyer taps it and finds nothing to
     * buy — so it drops out and the strip is shorter rather than wrong. The same
     * wall the browse uses, for the same reason.
     *
     * @param array<int, string> $rankedUuids
     *
     * @return array<int, array<string, mixed>>
     */
    public function cardsForUuids(array $rankedUuids, int $limit = 12): array
    {
        if ($rankedUuids === []) {
            return [];
        }

        /*
        | THE SAME INDEXED FLAG THE BROWSE USES (ADR-079). A strip asked for at
        | most thirty-six uuids, so the old narrowed `sellableProductUuids($uuids)`
        | was never the slow call — but two definitions of "sellable" on one
        | storefront is one too many, and this way a product appears and disappears
        | from a strip and a listing at the same moment.
        */
        /** @var array<int, Product> $products */
        $products = Product::query()
            ->where('status', ProductStatus::Published->value)
            ->where('is_sellable', true)
            ->whereIn('uuid', $rankedUuids)
            ->with(['brand', 'category', 'media'])
            ->get()
            ->all();

        $position = array_flip(array_values($rankedUuids));

        usort(
            $products,
            static fn (Product $a, Product $b): int => ($position[$a->uuid] ?? PHP_INT_MAX)
                <=> ($position[$b->uuid] ?? PHP_INT_MAX),
        );

        return array_map(
            fn (Product $product): array => $this->card($product),
            array_slice($products, 0, max(1, $limit)),
        );
    }

    /**
     * The choices a shopper can still make from here (ADR-080).
     *
     * **SCOPED BY CATEGORY AND SEARCH, NOT BY BRAND OR PRICE.** Those two are the
     * facets themselves: a brand list that collapsed to the brand already picked
     * would leave the browser's back button as the only way to switch, and price
     * bounds that shrank to the filtered subset would make the range control
     * unable to widen again.
     *
     * **CACHED BRIEFLY, BECAUSE THE SCOPE KEY IS SMALL AND THE READ IS NOT.** The
     * price span asks Offer about every product in the category; the answer is the
     * same for every visitor and changes only as prices do. Five minutes keeps a
     * re-priced catalogue honest and keeps the listing off that read.
     *
     * @return array{brands: array<int, array<string, mixed>>, price: array{min: string|null, max: string|null}}
     */
    private function facets(string $query, ?string $categoryUuid): array
    {
        // `sha256`, not `md5`: the arch suite's security preset bans the weak
        // digests outright rather than case by case, which is the right default
        // even where — as here — the input is a search term and a uuid.
        $key = 'catalog.facets.'.hash('sha256', $query.'|'.($categoryUuid ?? ''));

        /** @var array{brands: array<int, array<string, mixed>>, price: array{min: string|null, max: string|null}} $facets */
        $facets = Cache::remember($key, self::FACET_TTL, function () use ($query, $categoryUuid): array {
            $scope = $this->baseQuery($query, $categoryUuid, null);

            /*
            | THE BRAND FACET IS CATALOG'S OWN — a grouped count over the same
            | indexed `status`/`is_sellable` path the listing uses (ADR-079), with
            | no offer read at all. Brands with nothing sellable never appear,
            | which is the point: a filter that returns nothing is not a choice.
            */
            $brands = (clone $scope)->getQuery()
                ->join('brands', 'brands.id', '=', 'products.brand_id')
                ->select(['brands.uuid', 'brands.slug', 'brands.name'])
                ->selectRaw('count(*) as total')
                ->groupBy('brands.uuid', 'brands.slug', 'brands.name')
                ->orderByDesc('total')
                ->orderBy('brands.name')
                ->limit(self::MAX_FACET_BRANDS)
                ->get()
                ->map(static fn (object $row): array => [
                    'uuid' => (string) $row->uuid,
                    'slug' => (string) $row->slug,
                    'name' => (string) $row->name,
                    'count' => (int) $row->total,
                ])
                ->all();

            /** @var array<int, string> $uuids */
            $uuids = (clone $scope)->pluck('uuid')->all();

            $span = $this->offers->buyBoxPriceSpanFor($uuids);

            return [
                'brands' => $brands,
                // DECIMAL STRINGS ON THE WIRE, minor units inside (ADR-005). The
                // range control renders these; it never does arithmetic on them.
                'price' => [
                    'min' => $span['min'] === null ? null : $this->decimal($span['min']),
                    'max' => $span['max'] === null ? null : $this->decimal($span['max']),
                ],
            ];
        });

        return $facets;
    }

    /**
     * Which products in scope fall inside the requested price range.
     *
     * **NULL MEANS "NO RANGE WAS ASKED FOR"**, which is not the same as an empty
     * array: one leaves the listing untouched, the other narrows it to nothing.
     * Conflating them is how a filter nobody set empties a page.
     *
     * **BOUNDS ARE INCLUSIVE.** A shopper who types 100–200 means to see the thing
     * that costs exactly 200, and a range control's handles sit ON the numbers it
     * shows.
     *
     * @return array<int, string>|null
     */
    private function uuidsWithinPrice(
        string $query,
        ?string $categoryUuid,
        ?int $priceMinMinor,
        ?int $priceMaxMinor,
    ): ?array {
        if ($priceMinMinor === null && $priceMaxMinor === null) {
            return null;
        }

        /** @var array<int, string> $uuids */
        $uuids = $this->baseQuery($query, $categoryUuid, null)->pluck('uuid')->all();

        $prices = $this->offers->buyBoxPricesFor($uuids);

        $within = [];

        foreach ($prices as $uuid => $price) {
            $minor = (int) $price['price_minor'];

            if ($priceMinMinor !== null && $minor < $priceMinMinor) {
                continue;
            }

            if ($priceMaxMinor !== null && $minor > $priceMaxMinor) {
                continue;
            }

            $within[] = (string) $uuid;
        }

        return $within;
    }

    /**
     * Minor units → the decimal string the wire carries (ADR-005).
     */
    private function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    /**
     * The default listing: newest published first, paginated by the database.
     *
     * @param array<int, string>|null $withinPrice
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    private function byNewest(
        string $query,
        ?string $categoryUuid,
        ?string $brandUuid,
        int $page,
        int $perPage,
        ?array $withinPrice = null,
    ): array {
        $paginator = $this->baseQuery($query, $categoryUuid, $brandUuid, $withinPrice)
            // What just arrived, not what has sat unsold for a year — the same
            // default the seller-facing browse uses.
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);

        /** @var array<int, Product> $products */
        $products = $paginator->getCollection()->all();

        return [
            'items' => array_map(fn (Product $product): array => $this->card($product), $products),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /**
     * Cheapest or dearest first — ordered by a number Catalog does not hold.
     *
     * THE ORDER IS DECIDED BEFORE THE CONTENT IS FETCHED. Offer supplies a price
     * per product, the uuid list is sorted and sliced here, and only then does
     * Catalog read the page's rows — reordered in PHP to match, because a
     * `whereIn` returns rows in whatever order the database likes.
     *
     * TWO QUERIES INSTEAD OF ONE, and no join across a module boundary. The
     * alternative — a price column on the product — is exactly what ADR-037
     * forbids and what would make one product sellable at one price.
     *
     * @param array<int, string>|null $withinPrice
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    private function byPrice(
        string $query,
        ?string $categoryUuid,
        ?string $brandUuid,
        string $sort,
        int $page,
        int $perPage,
        ?array $withinPrice = null,
    ): array {
        // Which of the sellable products also match the buyer's filters. Ids only:
        // the content for the page is fetched after the order is known.
        /** @var array<int, string> $matching */
        $matching = $this->baseQuery($query, $categoryUuid, $brandUuid, $withinPrice)
            ->pluck('uuid')
            ->all();

        if ($matching === []) {
            return $this->empty($page, $perPage);
        }

        $prices = $this->offers->buyBoxPricesFor($matching);

        // A matching product with no price is one whose last offer went away
        // between the two reads — rare, and dropping it is the honest answer,
        // since a "from ₺—" card is not a card.
        $ordered = array_values(array_filter(
            $matching,
            static fn (string $uuid): bool => isset($prices[$uuid]),
        ));

        usort($ordered, static function (string $a, string $b) use ($prices, $sort): int {
            $comparison = $prices[$a]['price_minor'] <=> $prices[$b]['price_minor'];

            return $sort === self::SORT_PRICE_DESC ? -$comparison : $comparison;
        });

        $total = count($ordered);
        $pageUuids = array_slice($ordered, ($page - 1) * $perPage, $perPage);

        if ($pageUuids === []) {
            return $this->empty($page, $perPage, $total);
        }

        $products = Product::query()
            ->with(['brand', 'category', 'media'])
            ->whereIn('uuid', $pageUuids)
            ->get()
            ->keyBy('uuid');

        $items = [];

        foreach ($pageUuids as $uuid) {
            $product = $products->get($uuid);

            if ($product instanceof Product) {
                // Re-ordered explicitly: `whereIn` returns rows in the database's
                // order, which is not the price order just computed.
                $items[] = $this->card($product);
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Published, sellable, and matching the buyer's filters.
     *
     * @param array<int, string>|null $withinPrice
     *
     * @return Builder<Product>
     */
    private function baseQuery(
        string $query,
        ?string $categoryUuid,
        ?string $brandUuid,
        ?array $withinPrice = null,
    ): Builder {
        $builder = Product::query()
            ->where('status', ProductStatus::Published->value)
            // The composite index is (status, is_sellable), in that order.
            ->where('is_sellable', true)
            ->with(['brand', 'category', 'media']);

        $this->applyText($builder, $query);
        $this->applyCategory($builder, $categoryUuid);
        $this->applyBrand($builder, $brandUuid);

        /*
        | THE PRICE FILTER ARRIVES AS A UUID SET, not as a predicate, because the
        | price is not a column here and never will be (ADR-037/042): it belongs
        | to an Offer, and one product has as many prices as it has sellers. Offer
        | answers which products fall in the range; Catalog narrows to them.
        */
        if ($withinPrice !== null) {
            $builder->whereIn('uuid', $withinPrice);
        }

        return $builder;
    }

    /**
     * The buyer's card. NO PRICE — the storefront overlays it from Offer's batch
     * read (Storefront.md §1.1/§1.2), which is what keeps this a Catalog payload.
     *
     * @return array<string, mixed>
     */
    private function card(Product $product): array
    {
        return [
            'uuid' => $product->uuid,
            'slug' => $product->slug,
            'title' => $product->localized('title'),
            // The listing card image, straight off the model helper so the URL rule
            // (public disk, conversion) stays in one place. `preview` (~620px), not
            // `thumb` (160px box → ~119px wide): the storefront card renders it near
            // 200px+ and upscaling a 119px thumb there is visibly blurry. Null when
            // the seller uploaded nothing — a client renders a placeholder rather
            // than a broken image.
            'primary_image_url' => $product->imageUrl('preview'),
            'category' => [
                'uuid' => $product->category->uuid,
                'name' => $product->category->localized('name'),
            ],
            'brand' => $product->brand === null ? null : [
                'uuid' => $product->brand->uuid,
                'name' => $product->brand->name,
            ],
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    private function empty(int $page, int $perPage, int $total = 0): array
    {
        return [
            'items' => [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Free-text over title and description.
     *
     * THE SAME `LIKE` BRANCHING AS THE SELLER BROWSE, and the same reason: SQLite's
     * `LOWER()` is ASCII-only, so a Turkish title matches nothing under it. pgsql
     * gets `ILIKE`; the suite gets `LIKE` and a documented difference rather than a
     * test that passes for the wrong reason.
     *
     * Buyer-facing relevance ranking is a later refinement (Storefront.md §1.1) —
     * this is a filter, not a search engine.
     *
     * @param Builder<Product> $builder
     */
    private function applyText(Builder $builder, string $query): void
    {
        $query = trim($query);

        if ($query === '') {
            return;
        }

        $operator = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $term = '%'.$query.'%';

        $builder->where(function (Builder $inner) use ($operator, $term): void {
            foreach (['title_tr', 'title_en', 'description_tr', 'description_en'] as $column) {
                $inner->orWhere($column, $operator, $term);
            }
        });
    }

    /**
     * A category filter includes DESCENDANTS: a shopper picking "Giyim" expects a
     * t-shirt filed three levels down, because they think in departments.
     *
     * @param Builder<Product> $builder
     */
    private function applyCategory(Builder $builder, ?string $categoryUuid): void
    {
        if ($categoryUuid === null || $categoryUuid === '') {
            return;
        }

        /*
        | THE CRASH THIS METHOD SHIPPED. `?category=Dermokozmetik` reached here as
        | a plain name and `where('uuid', 'Dermokozmetik')` is SQLSTATE[22P02] on
        | PostgreSQL — a 500, not a miss — while SQLite's text column made every
        | test pass (ADR-059, `PublicKey`). A value that is not uuid-shaped now
        | never touches the column; it matches nothing, which is what the block
        | below was always meant to do.
        */
        $category = PublicKey::looksLikeUuid($categoryUuid)
            ? Category::query()->where('uuid', $categoryUuid)->active()->first()
            : null;

        if ($category === null) {
            // An unknown or deactivated category matches nothing rather than
            // silently returning everything — a filter that quietly stops
            // filtering is worse than an empty result.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn(
            'category_id',
            Category::query()
                ->where('path', 'like', $category->path.'%')
                ->select('id'),
        );
    }

    /**
     * @param Builder<Product> $builder
     */
    private function applyBrand(Builder $builder, ?string $brandUuid): void
    {
        if ($brandUuid === null || $brandUuid === '') {
            return;
        }

        // Same guard as the category filter above, and the same reason.
        if (! PublicKey::looksLikeUuid($brandUuid)) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereHas('brand', static fn (Builder $brand): Builder => $brand->where('uuid', $brandUuid));
    }
}
