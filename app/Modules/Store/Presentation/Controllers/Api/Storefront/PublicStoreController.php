<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Controllers\Api\Storefront;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Store\Application\PublicStorefront\PublicStorefrontAssembler;
use App\Modules\Store\Domain\Contracts\StoreRepositoryContract;
use App\Modules\Store\Domain\Exceptions\StorefrontException;
use App\Modules\Store\Domain\Models\Store;
use App\Modules\Store\Presentation\Resources\PublicStoreResource;
use Illuminate\Http\JsonResponse;

/**
 * The public storefront read surface (ADR-034/035/036).
 *
 * UNAUTHENTICATED and slug-resolved — the marketplace's canonical public entry
 * point at `/store/{slug}` (localised `/magaza/{slug}`). Only an ACTIVE store
 * renders; a missing or non-live store returns the same 404 so existence never
 * leaks. Core comes from the store; other modules' sections are composed in by
 * the assembler (ADR-036). No internal state, no private fields.
 *
 * @see docs/modules/Store.md §12
 */
final class PublicStoreController extends BaseController
{
    public function __construct(
        private readonly StoreRepositoryContract $stores,
        private readonly PublicStorefrontAssembler $assembler,
    ) {}

    /**
     * GET /api/v1/stores — every live shop's slug, for the sitemap.
     *
     * **THE SAME VISIBILITY RULE THE STORE PAGE USES, AND THAT IS THE WHOLE
     * POINT.** A suspended shop 404s at `/magaza/{slug}`; listing it here would put
     * a soft-404 in the sitemap and teach a crawler that this site advertises pages
     * it does not serve. `scopePubliclyVisible()` is the one definition both read.
     *
     * **`updated_at` RIDES ALONG SO `lastmod` CAN BE REAL.** A catalogue whose
     * prices and stock move daily has freshness to declare; a sitemap without it
     * declares nothing.
     *
     * Slugs and timestamps only — no names, no branding, nothing a scraper could
     * not read off the pages themselves in the same number of requests.
     */
    public function index(): JsonResponse
    {
        /** @var array<int, Store> $stores */
        $stores = Store::query()
            ->publiclyVisible()
            // Stable ordering, so a cached page and a fresh one agree.
            ->orderBy('slug')
            ->get(['slug', 'updated_at'])
            ->all();

        return $this->ok(array_map(static fn (Store $store): array => [
            'slug' => $store->slug,
            'updated_at' => $store->updated_at->toIso8601String(),
        ], $stores));
    }

    public function show(string $slug): JsonResponse
    {
        $store = $this->stores->findPublishedBySlug($slug);

        if ($store === null) {
            throw StorefrontException::unavailable();
        }

        return $this->ok(
            (new PublicStoreResource($store))->withExtensions($this->assembler->extensionsFor($store)),
        );
    }
}
