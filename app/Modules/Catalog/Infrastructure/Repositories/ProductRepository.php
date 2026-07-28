<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Repositories;

use App\Modules\Catalog\Domain\Contracts\ProductRepositoryContract;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The catalog's read vocabulary for products and SKUs.
 *
 * EAGER LOADS ARE DECLARED HERE, not at the call site (CLAUDE.md "strict mode is
 * on"). A product is never displayed without its category and brand, and never
 * moderated without its variants — so the moderation queue and the seller's
 * table both get them without either rediscovering the lazy-load exception.
 *
 * `variants.attributeValues` is on the list because rendering a variant means
 * rendering its combination label, which reads those values. Without it, a
 * 12-variant product would throw on the first row.
 *
 * @see App\Modules\Catalog\Domain\Contracts\ProductRepositoryContract
 */
final class ProductRepository implements ProductRepositoryContract
{
    /**
     * @var list<string>
     */
    private array $with = ['category', 'brand'];

    /**
     * The heavier load for a single product's detail view — everything the
     * moderation screen renders, in one pass.
     *
     * @var list<string>
     */
    private array $withDetail = [
        'category',
        'brand',
        'variants',
        'variants.attributeValues',
        'attributes',
        'media',
    ];

    public function findByUuid(string $uuid): ?Product
    {
        return Product::query()->with($this->withDetail)->where('uuid', $uuid)->first();
    }

    public function findOrFailByUuid(string $uuid): Product
    {
        $product = $this->findByUuid($uuid);

        if ($product === null) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$uuid]);
        }

        return $product;
    }

    public function findBySlug(string $slug): ?Product
    {
        return Product::query()->with($this->withDetail)->where('slug', $slug)->first();
    }

    public function findByGtin(string $gtin): ?Product
    {
        return Product::query()->with($this->with)->where('gtin', $gtin)->first();
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return Product::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    /**
     * Includes soft-deleted rows: the UNIQUE index does too, so a check that
     * ignored them would report "free" and then fail on insert.
     */
    public function gtinExists(string $gtin, ?int $exceptId = null): bool
    {
        return Product::query()
            ->withTrashed()
            ->where('gtin', $gtin)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function findVariantByUuid(string $uuid): ?ProductVariant
    {
        return ProductVariant::query()->with(['attributeValues', 'media'])->where('uuid', $uuid)->first();
    }

    public function skuExists(string $sku): bool
    {
        return ProductVariant::query()->withTrashed()->where('sku', $sku)->exists();
    }

    /**
     * §13.2 — SUGGEST, never auto-merge.
     *
     * GTIN first, because it is an exact identity claim about a manufactured
     * product; then a loose title match, optionally narrowed to the same brand.
     * Deliberately dumb: the point is to put plausible candidates in front of a
     * human, not to decide anything. Archived products are excluded — offering
     * a seller a delisted entry to "use instead" would be worse than a
     * duplicate.
     *
     * @return Collection<int, Product>
     */
    public function suggestDuplicatesFor(string $title, ?string $gtin = null, ?int $brandId = null): Collection
    {
        $limit = (int) config('catalog.duplicates.suggestion_limit', 5);

        $query = Product::query()
            ->with($this->with)
            ->whereNot('status', 'archived');

        if ($gtin !== null && $gtin !== '') {
            $exact = (clone $query)->where('gtin', $gtin)->limit($limit)->get();

            if ($exact->isNotEmpty()) {
                return $exact;
            }
        }

        $needle = trim($title);

        if ($needle === '') {
            return Product::query()->whereRaw('1 = 0')->get();
        }

        return $query
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->where(function ($q) use ($needle): void {
                foreach (Product::localizedColumns('title') as $column) {
                    $q->orWhere($column, 'like', '%'.$needle.'%');
                }
            })
            ->limit($limit)
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function awaitingModeration(int $perPage = 25): LengthAwarePaginator
    {
        return Product::query()
            ->with($this->with)
            ->awaitingModeration()
            ->paginate(min($perPage, (int) config('marketplace.pagination.max_per_page', 100)));
    }

    /**
     * @param  array<int, int>  $organizationIds
     * @return Collection<int, Product>
     */
    public function proposedByAny(array $organizationIds): Collection
    {
        return Product::query()
            ->with($this->with)
            ->proposedByAny($organizationIds)
            ->orderByDesc('id')
            ->get();
    }
}
