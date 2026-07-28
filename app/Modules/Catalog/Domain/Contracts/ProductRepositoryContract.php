<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contracts;

use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * The persistence port for products and their SKUs.
 *
 * `findByGtin` is the shared catalog's dedup lookup (§3.4) and
 * `suggestDuplicatesFor` is the §13.2 ruling in one method — SUGGEST on author,
 * never auto-merge. Silently fusing two products is unrecoverable, so the
 * platform surfaces candidates and lets a human decide.
 *
 * `proposedBy` is the seller scoping wall (ADR-040): by org uuid, because
 * Catalog holds no Organization relation.
 *
 * @see App\Modules\Catalog\Infrastructure\Repositories\ProductRepository
 */
interface ProductRepositoryContract
{
    public function findByUuid(string $uuid): ?Product;

    public function findOrFailByUuid(string $uuid): Product;

    public function findBySlug(string $slug): ?Product;

    public function findByGtin(string $gtin): ?Product;

    public function slugExists(string $slug, ?int $exceptId = null): bool;

    public function gtinExists(string $gtin, ?int $exceptId = null): bool;

    public function findVariantByUuid(string $uuid): ?ProductVariant;

    public function skuExists(string $sku): bool;

    /**
     * Likely existing matches for a product a seller is authoring (§13.2):
     * exact GTIN first, then title/brand similarity. Never merges anything.
     *
     * @return Collection<int, Product>
     */
    public function suggestDuplicatesFor(string $title, ?string $gtin = null, ?int $brandId = null): Collection;

    /**
     * The moderation queue, oldest submission first (§5).
     *
     * @return LengthAwarePaginator<int, Product>
     */
    public function awaitingModeration(int $perPage = 25): LengthAwarePaginator;

    /**
     * The proposals of the organizations a seller belongs to, and only those
     * (ADR-030/040). Takes the id list the Core
     * `OrganizationAuthorizationContract` returns, verbatim.
     *
     * @param  array<int, int>  $organizationIds
     * @return Collection<int, Product>
     */
    public function proposedByAny(array $organizationIds): Collection;
}
