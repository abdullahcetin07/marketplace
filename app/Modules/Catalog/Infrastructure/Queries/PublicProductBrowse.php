<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Queries;

use App\Core\Domain\Contracts\OfferQueryContract;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Database\Eloquent\Builder;
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
 * paginate. So the sellable set is resolved first and becomes a `whereIn`.
 *
 * ITS COST, STATED (Storefront.md §1.1): that set grows with the catalogue, and a
 * `whereIn` of a hundred thousand uuids will eventually be the slow part of this
 * page. The scaling path is denormalization — a `sellable` flag on the product
 * kept current by Offer's events, or the same fact carried on the search index —
 * and it is deliberately NOT built now: it is a second source of truth for
 * something computed correctly today, and building it before there is traffic to
 * justify it would be guessing at the shape of the fix.
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
    private const int MAX_PER_PAGE = 48;

    public const string SORT_NEWEST = 'newest';

    public const string SORT_PRICE_ASC = 'price_asc';

    public const string SORT_PRICE_DESC = 'price_desc';

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
     * }
     */
    public function cards(
        string $query = '',
        ?string $categoryUuid = null,
        ?string $brandUuid = null,
        string $sort = self::SORT_NEWEST,
        int $page = 1,
        int $perPage = 24,
    ): array {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $page = max(1, $page);

        // THE SELLABLE WALL. Everything below narrows within it; nothing can widen
        // past it, so an unsold or unpublished product cannot reach a buyer
        // whatever else the filters say.
        $sellable = $this->offers->sellableProductUuids();

        if ($sellable === []) {
            return $this->empty($page, $perPage);
        }

        return $sort === self::SORT_NEWEST
            ? $this->byNewest($query, $categoryUuid, $brandUuid, $sellable, $page, $perPage)
            : $this->byPrice($query, $categoryUuid, $brandUuid, $sellable, $sort, $page, $perPage);
    }

    /**
     * The default listing: newest published first, paginated by the database.
     *
     * @param  array<int, string>  $sellable
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    private function byNewest(
        string $query,
        ?string $categoryUuid,
        ?string $brandUuid,
        array $sellable,
        int $page,
        int $perPage,
    ): array {
        $paginator = $this->baseQuery($query, $categoryUuid, $brandUuid, $sellable)
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
     * @param  array<int, string>  $sellable
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    private function byPrice(
        string $query,
        ?string $categoryUuid,
        ?string $brandUuid,
        array $sellable,
        string $sort,
        int $page,
        int $perPage,
    ): array {
        // Which of the sellable products also match the buyer's filters. Ids only:
        // the content for the page is fetched after the order is known.
        /** @var array<int, string> $matching */
        $matching = $this->baseQuery($query, $categoryUuid, $brandUuid, $sellable)
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
     * @param  array<int, string>  $sellable
     * @return Builder<Product>
     */
    private function baseQuery(
        string $query,
        ?string $categoryUuid,
        ?string $brandUuid,
        array $sellable,
    ): Builder {
        $builder = Product::query()
            ->where('status', ProductStatus::Published->value)
            ->whereIn('uuid', $sellable)
            ->with(['brand', 'category', 'media']);

        $this->applyText($builder, $query);
        $this->applyCategory($builder, $categoryUuid);
        $this->applyBrand($builder, $brandUuid);

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
            // The listing thumbnail, straight off the model helper so the URL rule
            // (public disk, conversion) stays in one place. Null when the seller
            // uploaded nothing — a client renders a placeholder rather than a
            // broken image.
            'primary_image_url' => $product->imageUrl('thumb'),
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
     * @param  Builder<Product>  $builder
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
     * @param  Builder<Product>  $builder
     */
    private function applyCategory(Builder $builder, ?string $categoryUuid): void
    {
        if ($categoryUuid === null || $categoryUuid === '') {
            return;
        }

        $category = Category::query()->where('uuid', $categoryUuid)->active()->first();

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
     * @param  Builder<Product>  $builder
     */
    private function applyBrand(Builder $builder, ?string $brandUuid): void
    {
        if ($brandUuid === null || $brandUuid === '') {
            return;
        }

        $builder->whereHas('brand', static fn (Builder $brand): Builder => $brand->where('uuid', $brandUuid));
    }
}
