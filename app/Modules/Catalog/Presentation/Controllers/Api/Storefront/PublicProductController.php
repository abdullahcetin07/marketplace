<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers\Api\Storefront;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Catalog\Domain\Contracts\SlugRegistryContract;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\SluggableType;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Infrastructure\Queries\PublicProductBrowse;
use App\Modules\Catalog\Presentation\Resources\PublicProductCardResource;
use App\Modules\Catalog\Presentation\Resources\PublicProductResource;
use App\Shared\Support\PublicKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The marketplace-wide product browse and the product page (ADR-058,
 * Storefront.md §1.1).
 *
 * THE FIRST SURFACE ON THIS PLATFORM THAT ANSWERS "WHAT CAN I BUY HERE". Until
 * now a buyer could read one store (`/store/{slug}`) or one product's sellers
 * (`/products/{uuid}/offers`); nothing let them look across the marketplace.
 *
 * UNAUTHENTICATED, ON THE STOREFRONT THROTTLE, uuid-resolved — the ADR-034 public
 * shape, mirrored. This is anonymous browsing traffic, not API traffic, and it is
 * about to be the busiest surface the platform has.
 *
 * IT SHOWS ONLY WHAT CAN BE BOUGHT. The browse composes with Offer through the
 * Core contract (`PublicProductBrowse`), so a published product nobody stocks
 * never appears — clicking a card must not land on "unavailable".
 *
 * BUT THE DETAIL PAGE IS PUBLISHED-ONLY, NOT SELLABLE-ONLY, and the difference is
 * deliberate: a buyer arrives here from a bookmark, a shared link or a search
 * engine long after the last seller ran out. That page is real and should render,
 * with the buy box saying nothing is available — which is exactly what the
 * existing offers route already does. 404ing it would break every link the
 * platform has ever emitted the moment stock ran out.
 *
 * NO PRICE IN EITHER RESPONSE (ADR-037). The storefront overlays it: the listing
 * from `POST /offers/prices`, the page from `GET /products/{uuid}/offers`.
 *
 * @see App\Modules\Catalog\Infrastructure\Queries\PublicProductBrowse
 * @see docs/modules/Storefront.md §1.1
 */
final class PublicProductController extends BaseController
{
    public function __construct(
        private readonly PublicProductBrowse $browse,
        private readonly SlugRegistryContract $slugs,
    ) {}

    /**
     * GET /api/v1/products
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->browse->cards(
            query: (string) $request->query('q', ''),
            categoryUuid: $this->filterUuid($request, 'category', SluggableType::Category),
            brandUuid: $this->filterUuid($request, 'brand', SluggableType::Brand),
            sort: $this->sort($request),
            page: (int) $request->query('page', 1),
            perPage: $this->perPage(default: 24, max: 48),
            priceMinMinor: $this->priceMinor($request, 'price_min'),
            priceMaxMinor: $this->priceMinor($request, 'price_max'),
        );

        return $this->ok(
            array_map(
                static fn (array $card): PublicProductCardResource => new PublicProductCardResource($card),
                $result['items'],
            ),
            null,
            [
                'current_page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'last_page' => $result['last_page'],
                /*
                | THE CHOICES, BESIDE THE RESULTS (ADR-080). In `meta` rather than
                | in `data` because a facet is not a product: a client that
                | rendered `data` as cards and found a brand list among them would
                | be right to be confused, and pagination counts stay about the
                | grid.
                */
                'facets' => $result['facets'],
            ],
        );
    }

    /**
     * GET /api/v1/products/{product}
     */
    public function show(string $product): JsonResponse
    {
        /*
        | BY SHAPE, NEVER BY TRIAL (ADR-059). This used to be `where('uuid',
        | $product)` unconditionally, so `/products/avene-cicalfate-krem` — the
        | flat scheme's whole point — was SQLSTATE[22P02] and a 500 on PostgreSQL
        | while every SQLite test passed. Third time the platform shipped that
        | shape of bug; see `PublicKey`.
        */
        $uuid = $this->productUuid($product);

        $model = $uuid === null ? null : Product::query()
            ->with(['brand', 'category', 'media', 'variants', 'variants.attributeValues', 'descriptiveAttributes', 'descriptiveAttributes.values'])
            ->where('uuid', $uuid)
            ->where('status', ProductStatus::Published->value)
            ->first();

        if ($model === null) {
            /*
             * ONE 404 FOR "no such product" AND "not published". A draft's
             * existence must not be discoverable by watching which uuids answer
             * differently — the same rule the store page and the buy box keep.
             */
            throw new NotFoundHttpException;
        }

        return $this->ok(new PublicProductResource($model, $this->categoryPath($model)));
    }

    /**
     * A price bound off the query string, in minor units (ADR-005/080).
     *
     * **A DECIMAL STRING IN, KURUŞ OUT, ONCE, AT THE BOUNDARY.** `49.90` is money
     * the moment it stops being text, and the float in between is the bug the
     * minor-units rule exists to prevent.
     *
     * **AN UNREADABLE BOUND IS NO BOUND, NOT A ZERO.** `price_min=abc` filtering
     * to "at least ₺0.00" is a filter the shopper did not ask for; ignoring it
     * shows them the listing they expected. A comma is accepted because Turkish
     * writes one and a URL somebody typed by hand will carry it.
     */
    private function priceMinor(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        if (! is_string($value)) {
            return null;
        }

        $value = str_replace(',', '.', trim($value));

        if ($value === '' || preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    /**
     * The breadcrumb, root first.
     *
     * Read from the materialised path (Catalog §13.1) rather than walked up
     * parent by parent: one query for the whole ancestry, and the same mechanism
     * the subtree filter uses.
     *
     * @return array<int, array{uuid: string, name: string, slug: string}>
     */
    private function categoryPath(Product $product): array
    {
        $ids = array_filter(explode('/', trim($product->category->path, '/')));

        if ($ids === []) {
            return [];
        }

        return Category::query()
            ->whereIn('id', $ids)
            ->orderBy('depth')
            ->get()
            ->map(static fn (Category $category): array => [
                'uuid' => $category->uuid,
                'name' => $category->localized('name'),
                // The crumb is a link (ADR-059), so it needs its address.
                'slug' => $category->slug,
            ])
            ->all();
    }

    /**
     * A sort the browse understands, or the default.
     *
     * VALIDATED BY ALLOW-LIST rather than passed through: an unrecognised value
     * silently becoming "no ordering" would make a listing's order depend on the
     * database's mood, which is the kind of bug that never reproduces.
     */
    private function sort(Request $request): string
    {
        $sort = (string) $request->query('sort', PublicProductBrowse::SORT_NEWEST);

        return in_array($sort, [
            PublicProductBrowse::SORT_NEWEST,
            PublicProductBrowse::SORT_PRICE_ASC,
            PublicProductBrowse::SORT_PRICE_DESC,
        ], true) ? $sort : PublicProductBrowse::SORT_NEWEST;
    }

    /**
     * The product uuid behind a uuid OR a slug, or null.
     */
    private function productUuid(string $value): ?string
    {
        if (PublicKey::looksLikeUuid($value)) {
            return $value;
        }

        $match = $this->slugs->resolve($value);

        // A slug that points at a category or a brand is not a product, and
        // answering with one would let `/products/{brandSlug}` render something
        // unrelated rather than 404.
        return $match !== null && $match->type === SluggableType::Product ? $match->uuid : null;
    }

    /**
     * A filter value as a uuid the browse can use — accepting a slug too.
     *
     * AN UNRESOLVABLE FILTER RETURNS A SENTINEL, NOT NULL, which is the subtle
     * half. Null means "no filter" and would silently return the WHOLE catalogue
     * for `?category=made-up` — a filter that quietly stops filtering. The browse
     * treats an unknown uuid as matching nothing, so passing the raw value
     * through gives the honest empty result. It is only ever compared, never
     * cast: `PublicProductBrowse` looks it up by shape too.
     */
    private function filterUuid(Request $request, string $key, SluggableType $type): ?string
    {
        $value = $request->query($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (PublicKey::looksLikeUuid($value)) {
            return $value;
        }

        $match = $this->slugs->resolve($value);

        return $match !== null && $match->type === $type ? $match->uuid : $value;
    }
}
