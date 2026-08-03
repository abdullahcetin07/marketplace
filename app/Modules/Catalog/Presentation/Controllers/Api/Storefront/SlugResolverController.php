<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers\Api\Storefront;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Catalog\Domain\Contracts\SlugRegistryContract;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\SluggableType;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What lives at this address? (ADR-059)
 *
 * THE ONE ENDPOINT THE FLAT URL SCHEME REQUIRES. `/bioderma`, `/cilt-bakimi` and
 * `/avene-cicalfate-krem` are indistinguishable to a client: there is no prefix to
 * branch on, so the storefront's single catch-all route has to ask before it can
 * render. The alternative — try the product endpoint, then the category one, then
 * the brand one — is three requests per page load, two of them 404s, on every
 * first paint.
 *
 * IT RETURNS A TYPE AND A UUID, NOT A PAGE. Loading the aggregate here would make
 * one endpoint pay for three different eager-load shapes, and the client is about
 * to call the right one anyway.
 *
 * `canonical_slug` IS THE REDIRECT SIGNAL. A slug is stable once issued, but when
 * one genuinely changes the old row survives as an alias (ADR-059) — so a visitor
 * on the old address gets the entity plus the address it has moved to, and the
 * storefront 301s. Without this field the platform would serve two URLs for one
 * product indefinitely, which is the duplicate-content problem the alias trail
 * exists to prevent.
 *
 * IT RESPECTS PUBLICATION. A registry row exists for a draft product from the
 * moment it is authored — the trait registers on save — so resolving without a
 * status check would let anyone discover unpublished products by guessing names.
 * A draft answers exactly like a slug that was never issued.
 *
 * @see App\Modules\Catalog\Infrastructure\Registries\SlugRegistry
 */
final class SlugResolverController extends BaseController
{
    public function __construct(private readonly SlugRegistryContract $slugs) {}

    /**
     * GET /api/v1/resolve/{slug}
     */
    public function show(string $slug): JsonResponse
    {
        $match = $this->slugs->resolve($slug);

        if ($match === null || ! $this->isPublic($match->type, $match->uuid)) {
            /*
             * ONE 404 FOR EVERY MISS — unknown, unpublished, deactivated, or a
             * brand nobody sells. A visitor cannot tell them apart, and telling
             * them apart is how a draft's existence leaks (ADR-034).
             */
            throw new NotFoundHttpException;
        }

        return $this->ok([
            'type' => $match->type->value,
            'id' => $match->uuid,
            'slug' => $match->slug,
            // Equal to `slug` on an ordinary hit; different means "this moved,
            // send a 301 there".
            'canonical_slug' => $match->canonicalSlug,
        ]);
    }

    /**
     * Whether the thing behind the slug is something a buyer may see.
     *
     * The rule per kind is the one its own public surface already applies, so the
     * resolver and the page it points at cannot disagree about what exists.
     */
    private function isPublic(SluggableType $type, string $uuid): bool
    {
        return match ($type) {
            // Published, not sellable: a product page renders for a buyer
            // arriving from a bookmark long after the last seller ran out
            // (Storefront.md §1.1).
            SluggableType::Product => Product::query()
                ->where('uuid', $uuid)
                ->where('status', ProductStatus::Published->value)
                ->exists(),

            SluggableType::Category => Category::query()
                ->where('uuid', $uuid)
                ->where('is_active', true)
                ->exists(),

            SluggableType::Brand => Brand::query()
                ->where('uuid', $uuid)
                ->where('is_active', true)
                ->exists(),
        };
    }
}
