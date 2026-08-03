<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Queries;

use App\Core\Domain\Contracts\OfferQueryContract;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * The category tree and the brand list a buyer navigates by (ADR-059).
 *
 * COUNTS ARE OF SELLABLE PRODUCTS, not of published ones, and that is the only
 * hard part of this class. A menu that says "Cilt Bakımı (48)" and opens on an
 * empty listing is worse than a menu with no counts: the listing already hides
 * anything nobody stocks (`PublicProductBrowse`), so a count taken from the
 * Catalog alone is a number the very next page contradicts.
 *
 * SO IT ASKS OFFER FIRST, through the Core contract, exactly as the browse does —
 * Catalog has no stock to count (ADR-037) and imports no Offer.
 *
 * ONE PASS FOR THE WHOLE TREE. The naive shape is a count per node, which on a
 * three-level taxonomy is one query per category on the platform's most-requested
 * fragment. Instead the sellable set is resolved once, counted per LEAF category
 * in one grouped query, and rolled up the materialised path in PHP — so a parent's
 * count includes everything filed beneath it, which is what a shopper picking
 * "Kozmetik" means.
 *
 * A CATALOG-INTERNAL CLASS, like `PublicProductBrowse` beside it: nothing outside
 * Catalog reads it, and publishing a port with one in-module caller is ceremony.
 *
 * @see App\Modules\Catalog\Infrastructure\Queries\PublicProductBrowse
 */
final class PublicTaxonomyBrowse
{
    public function __construct(private readonly OfferQueryContract $offers) {}

    /**
     * The active category tree, root first, each node carrying its subtree's
     * sellable product count.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(): array
    {
        $categories = Category::query()->active()->orderBy('position')->orderBy('id')->get();
        $counts = $this->sellableCountsByCategoryPath($categories->pluck('path', 'id')->all());

        $byParent = [];
        $uuidById = [];

        foreach ($categories as $category) {
            $byParent[$category->parent_id ?? 0][] = $category;
            $uuidById[(int) $category->getKey()] = $category->uuid;
        }

        return $this->branch($byParent, 0, $counts, $uuidById);
    }

    /**
     * One category with its breadcrumb and its immediate children, or null.
     *
     * @return array<string, mixed>|null
     */
    public function category(int $categoryId): ?array
    {
        $category = Category::query()->active()->whereKey($categoryId)->first();

        if ($category === null) {
            return null;
        }

        $categories = Category::query()->active()->orderBy('position')->orderBy('id')->get();
        $counts = $this->sellableCountsByCategoryPath($categories->pluck('path', 'id')->all());

        $ancestors = Category::query()
            ->active()
            ->whereIn('id', $category->ancestorIds())
            ->orderBy('depth')
            ->get();

        return [
            'uuid' => $category->uuid,
            'name' => $category->localized('name'),
            'slug' => $category->slug,
            'product_count' => $counts[(int) $category->getKey()] ?? 0,
            // Root first, and INCLUDING the category itself — a breadcrumb that
            // stops at the parent makes every client append the last crumb.
            'path' => $ancestors
                ->push($category)
                ->map(static fn (Category $node): array => [
                    'uuid' => $node->uuid,
                    'name' => $node->localized('name'),
                    'slug' => $node->slug,
                ])
                ->values()
                ->all(),
            'children' => $categories
                ->where('parent_id', $category->getKey())
                ->map(fn (Category $child): array => [
                    'uuid' => $child->uuid,
                    'name' => $child->localized('name'),
                    'slug' => $child->slug,
                    'product_count' => $counts[(int) $child->getKey()] ?? 0,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Active brands that somebody is actually selling, with their counts.
     *
     * BRANDS WITH NOTHING FOR SALE ARE OMITTED, not listed with a zero. A brand
     * filter that offers 400 names and returns nothing for 380 of them is a filter
     * a shopper stops trusting after the second empty page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function brands(): array
    {
        $counts = $this->sellableCountsBy('brand_id');

        if ($counts === []) {
            return [];
        }

        return Brand::query()
            ->where('is_active', true)
            ->whereIn('id', array_keys($counts))
            ->orderBy('name')
            ->get()
            ->map(static fn (Brand $brand): array => [
                'uuid' => $brand->uuid,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo_url' => $brand->imageUrl('thumb'),
                'product_count' => $counts[(int) $brand->getKey()] ?? 0,
            ])
            ->values()
            ->all();
    }

    /**
     * One brand, or null when it is inactive or gone.
     *
     * UNLIKE THE LIST, A BRAND WITH NOTHING FOR SALE STILL RENDERS — the same
     * distinction the product page keeps (Storefront.md §1.1). A buyer arrives
     * here from a bookmark or a search engine long after the last seller ran out,
     * and the page is real; 404ing it would break every link the moment stock did.
     *
     * @return array<string, mixed>|null
     */
    public function brand(int $brandId): ?array
    {
        $brand = Brand::query()->where('is_active', true)->whereKey($brandId)->first();

        if ($brand === null) {
            return null;
        }

        $counts = $this->sellableCountsBy('brand_id');

        return [
            'uuid' => $brand->uuid,
            'name' => $brand->name,
            'slug' => $brand->slug,
            'logo_url' => $brand->imageUrl('preview'),
            'product_count' => $counts[(int) $brand->getKey()] ?? 0,
        ];
    }

    /**
     * Recursively assemble one level of the tree.
     *
     * @param  array<int, array<int, Category>>  $byParent
     * @param  array<int, int>  $counts
     * @param  array<int, string>  $uuidById
     * @return array<int, array<string, mixed>>
     */
    private function branch(array $byParent, int $parentId, array $counts, array $uuidById): array
    {
        $nodes = [];

        foreach ($byParent[$parentId] ?? [] as $category) {
            $nodes[] = [
                'uuid' => $category->uuid,
                'name' => $category->localized('name'),
                'slug' => $category->slug,
                // The PARENT's uuid, not its id — this payload is public
                // (non-negotiable #7). Null at the root, which is how a client
                // knows where the tree starts without a separate flag.
                'parent_uuid' => $category->parent_id === null
                    ? null
                    : ($uuidById[(int) $category->parent_id] ?? null),
                'product_count' => $counts[(int) $category->getKey()] ?? 0,
                'children' => $this->branch($byParent, (int) $category->getKey(), $counts, $uuidById),
            ];
        }

        return $nodes;
    }

    /**
     * Sellable product counts per category id, rolled UP the tree.
     *
     * @param  array<int, string>  $pathsById
     * @return array<int, int>  category id => count including descendants
     */
    private function sellableCountsByCategoryPath(array $pathsById): array
    {
        $direct = $this->sellableCountsBy('category_id');

        if ($direct === []) {
            return [];
        }

        $totals = [];

        /*
        | THE ROLL-UP, in PHP and on the materialised path (§13.1). Every ancestor
        | id is already in the child's `path` — "/3/17/42/" — so a product filed at
        | 42 adds one to 3, 17 and 42 without a recursive query or a second read of
        | the tree.
        */
        foreach ($direct as $categoryId => $count) {
            $path = $pathsById[$categoryId] ?? '';
            $ids = array_filter(explode(Category::PATH_SEPARATOR, trim($path, Category::PATH_SEPARATOR)));

            foreach ($ids as $ancestorId) {
                $totals[(int) $ancestorId] = ($totals[(int) $ancestorId] ?? 0) + $count;
            }
        }

        return $totals;
    }

    /**
     * Sellable published products grouped by one column.
     *
     * @return array<int, int>
     */
    private function sellableCountsBy(string $column): array
    {
        // THE SELLABLE WALL, the same one the listing uses, so a count and the
        // page it opens cannot disagree (ADR-037/058).
        $sellable = $this->offers->sellableProductUuids();

        if ($sellable === []) {
            return [];
        }

        /** @var array<int, int> $counts */
        $counts = [];

        foreach (array_chunk($sellable, 1_000) as $chunk) {
            $rows = Product::query()
                ->where('status', ProductStatus::Published->value)
                ->whereIn('uuid', $chunk)
                ->whereNotNull($column)
                ->groupBy($column)
                ->select($column, DB::raw('count(*) as aggregate'))
                ->pluck('aggregate', $column);

            foreach ($rows as $key => $count) {
                $counts[(int) $key] = ($counts[(int) $key] ?? 0) + (int) $count;
            }
        }

        return $counts;
    }
}
