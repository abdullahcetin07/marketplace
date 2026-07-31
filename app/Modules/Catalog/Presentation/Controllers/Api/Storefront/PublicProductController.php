<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers\Api\Storefront;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Infrastructure\Queries\PublicProductBrowse;
use App\Modules\Catalog\Presentation\Resources\PublicProductCardResource;
use App\Modules\Catalog\Presentation\Resources\PublicProductResource;
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
    ) {}

    /**
     * GET /api/v1/products
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->browse->cards(
            query: (string) $request->query('q', ''),
            categoryUuid: $this->uuidParam($request, 'category'),
            brandUuid: $this->uuidParam($request, 'brand'),
            sort: $this->sort($request),
            page: (int) $request->query('page', 1),
            perPage: $this->perPage(default: 24, max: 48),
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
            ],
        );
    }

    /**
     * GET /api/v1/products/{product}
     */
    public function show(string $product): JsonResponse
    {
        $model = Product::query()
            ->with(['brand', 'category', 'media', 'variants', 'variants.attributeValues', 'descriptiveAttributes', 'descriptiveAttributes.values'])
            ->where('uuid', $product)
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
     * The breadcrumb, root first.
     *
     * Read from the materialised path (Catalog §13.1) rather than walked up
     * parent by parent: one query for the whole ancestry, and the same mechanism
     * the subtree filter uses.
     *
     * @return array<int, array{uuid: string, name: string}>
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
     * A uuid filter, or null — never a raw string reaching a query.
     */
    private function uuidParam(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
