<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers\Api\Storefront;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Core\Domain\Contracts\ReviewQueryContract;
use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Catalog\Domain\Contracts\SlugRegistryContract;
use App\Modules\Catalog\Domain\Enums\SluggableType;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Infrastructure\Queries\PublicProductBrowse;
use App\Modules\Catalog\Presentation\Resources\PublicProductCardResource;
use App\Shared\Support\PublicKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The storefront's three computed strips (ADR-077, ADR-078).
 *
 * **CATALOG OWNS THEM BECAUSE A PRODUCT CARD IS CATALOG'S.** What sold lives in
 * Order and what was reviewed lives in Reviews, and neither may build a card or be
 * imported here. Each endpoint is one Core call for the ORDER of things and one
 * hydration for the substance — the boundary is the whole design, not an obstacle
 * to it.
 *
 * **COMPUTED ON READ, WITH NO RECOMMENDATION TABLE.** There is nothing to rebuild,
 * nothing to invalidate and nothing to go stale against the orders it is derived
 * from; a sale counts the moment it is paid for. The cache below is what keeps
 * that affordable, and the follow-up when volume bites is a precomputed table read
 * in place of the Core call — the endpoints do not change shape.
 *
 * **EMPTY IS A FIRST-CLASS ANSWER.** A shop on its first day has no best sellers.
 * The storefront hides a strip that returns nothing and shows it the moment it
 * fills, so `[]` with a 200 is the contract, never a 404 and never a placeholder.
 *
 * @see docs/modules/Catalog.md — the storefront strips
 */
final class PublicProductRankingController extends BaseController
{
    /**
     * One hour. These are anonymous and identical for every visitor, and a
     * best-seller list that lags an hour behind is indistinguishable from one that
     * does not — while a live aggregate on every homepage hit is not.
     */
    private const int TTL = 3600;

    private const int LIMIT = 12;

    public function __construct(
        private readonly PublicProductBrowse $browse,
        private readonly OrderQueryContract $orders,
        private readonly ReviewQueryContract $reviews,
        private readonly SlugRegistryContract $slugs,
    ) {}

    /**
     * GET /api/v1/products/best-sellers
     */
    public function bestSellers(): JsonResponse
    {
        return $this->cards('catalog.strip.best-sellers', fn (): array => $this->browse->cardsForUuids(
            $this->orders->bestSellingProductUuids(self::LIMIT * 3),
            self::LIMIT,
        ));
    }

    /**
     * GET /api/v1/products/most-reviewed
     */
    public function mostReviewed(): JsonResponse
    {
        return $this->cards('catalog.strip.most-reviewed', fn (): array => $this->browse->cardsForUuids(
            $this->reviews->mostReviewedProductUuids(self::LIMIT * 3),
            self::LIMIT,
        ));
    }

    /**
     * GET /api/v1/products/{product}/also-bought
     */
    public function alsoBought(string $product): JsonResponse
    {
        $uuid = $this->resolve($product);

        return $this->cards(
            'catalog.strip.also-bought.'.$uuid,
            fn (): array => $this->browse->cardsForUuids(
                $this->orders->coPurchasedProductUuids($uuid, self::LIMIT * 3),
                self::LIMIT,
            ),
        );
    }

    /**
     * The shared tail: cache the cards, wrap them in the envelope.
     *
     * **MORE UUIDS ARE ASKED FOR THAN ARE SHOWN** (`LIMIT * 3`). The ranking knows
     * what sold; it does not know what is still sellable, and a strip that asked
     * for exactly twelve would come back with four the day eight best-sellers went
     * out of stock.
     *
     * **THE CARDS ARE CACHED, NOT THE UUIDS.** Hydration is the expensive half —
     * the sellable wall plus the product read — and caching only the ranking would
     * leave it running on every request.
     *
     * @param callable(): array<int, array<string, mixed>> $compute
     */
    private function cards(string $key, callable $compute): JsonResponse
    {
        /** @var array<int, array<string, mixed>> $cards */
        $cards = Cache::remember($key, self::TTL, static fn (): array => $compute());

        return $this->ok(array_map(
            static fn (array $card): PublicProductCardResource => new PublicProductCardResource($card),
            $cards,
        ));
    }

    /**
     * `{product}` by uuid or slug, the same way every other product route reads it.
     *
     * BY SHAPE, NEVER BY TRIAL (ADR-059): a slug handed to a `uuid` column is a
     * SQLSTATE[22P02] and a 500 on PostgreSQL while every SQLite test passes.
     */
    private function resolve(string $product): string
    {
        if (PublicKey::looksLikeUuid($product)) {
            if (! Product::query()->where('uuid', $product)->exists()) {
                throw new NotFoundHttpException;
            }

            return $product;
        }

        $match = $this->slugs->resolve($product);

        if ($match === null || $match->type !== SluggableType::Product) {
            throw new NotFoundHttpException;
        }

        return $match->uuid;
    }
}
