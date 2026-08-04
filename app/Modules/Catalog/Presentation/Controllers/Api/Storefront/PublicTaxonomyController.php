<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers\Api\Storefront;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Catalog\Domain\Contracts\SlugRegistryContract;
use App\Modules\Catalog\Domain\Enums\SluggableType;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Infrastructure\Queries\PublicTaxonomyBrowse;
use App\Modules\Catalog\Presentation\Resources\PublicBrandResource;
use App\Modules\Catalog\Presentation\Resources\PublicCategoryResource;
use App\Shared\Support\PublicKey;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The category tree and the brand list a buyer navigates by (ADR-059).
 *
 * WHAT THE STOREFRONT'S MENU IS MADE OF. Until this existed the header's
 * categories were hard-coded in the frontend, which meant a Category Manager could
 * open a department and no shopper would ever see it.
 *
 * SLUG-ADDRESSED, uuid-tolerant. `/categories/cilt-bakimi` is the URL a visitor
 * arrives on; `/categories/{uuid}` also resolves, because an internal caller
 * holding a uuid should not have to look up a slug first. Which one arrived is
 * decided BY SHAPE (`PublicKey`), never by trying the uuid column first — that is
 * the 500 this order was written to end.
 *
 * ANONYMOUS, ON THE STOREFRONT THROTTLE, like every other buyer surface.
 *
 * @see App\Modules\Catalog\Infrastructure\Queries\PublicTaxonomyBrowse
 */
final class PublicTaxonomyController extends BaseController
{
    public function __construct(
        private readonly PublicTaxonomyBrowse $taxonomy,
        private readonly SlugRegistryContract $slugs,
    ) {}

    /**
     * GET /api/v1/categories
     */
    public function categories(): JsonResponse
    {
        return $this->ok(array_map(
            static fn (array $node): PublicCategoryResource => new PublicCategoryResource($node),
            $this->taxonomy->tree(),
        ));
    }

    /**
     * GET /api/v1/categories/{slugOrId}
     */
    public function category(string $category): JsonResponse
    {
        $id = $this->idFor($category, SluggableType::Category, Category::class);

        $node = $id === null ? null : $this->taxonomy->category($id);

        if ($node === null) {
            // The same 404 for "no such category" and "deactivated" — an
            // operator's staging work must not be discoverable by watching which
            // slugs answer differently (ADR-034).
            throw new NotFoundHttpException;
        }

        return $this->ok(new PublicCategoryResource($node));
    }

    /**
     * GET /api/v1/brands
     */
    public function brands(): JsonResponse
    {
        return $this->ok(array_map(
            static fn (array $brand): PublicBrandResource => new PublicBrandResource($brand),
            $this->taxonomy->brands(),
        ));
    }

    /**
     * GET /api/v1/brands/{slugOrId}
     */
    public function brand(string $brand): JsonResponse
    {
        $id = $this->idFor($brand, SluggableType::Brand, Brand::class);

        $node = $id === null ? null : $this->taxonomy->brand($id);

        if ($node === null) {
            throw new NotFoundHttpException;
        }

        return $this->ok(new PublicBrandResource($node));
    }

    /**
     * The internal id behind a slug or a uuid, or null.
     *
     * RESOLVED BY SHAPE. A uuid-looking value goes to the `uuid` column and
     * anything else to the registry — never both, and never the uuid column
     * first. `where('uuid', 'cilt-bakimi')` on PostgreSQL is SQLSTATE[22P02], a
     * 500 rather than a miss, and this platform has shipped that bug twice
     * already (ADR-049's reservation reference, then the geo cascade).
     *
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     */
    private function idFor(string $value, SluggableType $type, string $model): ?int
    {
        if (PublicKey::looksLikeUuid($value)) {
            $id = $model::query()->where('uuid', $value)->value('id');

            return $id === null ? null : (int) $id;
        }

        $match = $this->slugs->resolve($value);

        if ($match === null || $match->type !== $type) {
            return null;
        }

        $id = $model::query()->where('uuid', $match->uuid)->value('id');

        return $id === null ? null : (int) $id;
    }
}
