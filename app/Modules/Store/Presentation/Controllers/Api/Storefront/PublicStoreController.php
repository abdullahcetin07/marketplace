<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Controllers\Api\Storefront;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Store\Application\PublicStorefront\PublicStorefrontAssembler;
use App\Modules\Store\Domain\Contracts\StoreRepositoryContract;
use App\Modules\Store\Domain\Exceptions\StorefrontException;
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
