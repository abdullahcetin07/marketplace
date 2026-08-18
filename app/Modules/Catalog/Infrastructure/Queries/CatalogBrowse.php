<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Queries;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Modules\Catalog\Domain\Contracts\SlugRegistryContract;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\SluggableType;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Shared\Support\PublicKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Catalog's implementation of the seller-facing browse port (ADR-046).
 *
 * THE ONE CATALOG CHANGE THE OFFER SPRINT MAKES: a read contract, no schema
 * change — which is exactly why Phase 1 was left unfrozen.
 *
 * WHY THIS READS THE DATABASE AND NOT THE SEARCH INDEX. Offer.md §8.2 describes
 * it as reading Catalog's existing index; it reads Postgres instead, for two
 * reasons worth stating rather than burying:
 *
 *  1. That index is tuned for BUYER relevance — Turkish analysis, field boosts,
 *     facets (docs/search.md). This is a seller picking a product they already
 *     have in hand, filtered by category and brand. Different question, and
 *     relevance ranking is not what makes it good.
 *  2. It would put a cluster on the critical path of an internal panel. A seller
 *     could not list a product because OpenSearch was down, and the suite —
 *     which runs `SCOUT_DRIVER=null` — could never exercise the flow at all.
 *
 * The cost: this will not scale to a catalog of millions the way an index does,
 * and free-text matching is a `LIKE`, not relevance-ranked. When buyer-facing
 * search lands (§10) and the catalog is large enough to need it, swapping this
 * for an index-backed implementation is a change of ONE container binding —
 * which is the whole reason it sits behind a contract.
 *
 * PUBLISHED ONLY, on every path. A draft must not become discoverable through
 * a browse endpoint, and `variantsForProduct` re-checks the parent rather than
 * trusting the caller to have come from a search result.
 *
 * @see App\Core\Domain\Contracts\CatalogBrowseContract
 * @see docs/modules/Offer.md §4, §8.2
 */
final class CatalogBrowse implements CatalogBrowseContract
{
    /**
     * A hard ceiling on page size, so a caller cannot ask for the whole catalog
     * in one request.
     */
    private const int MAX_PER_PAGE = 100;

    public function __construct(private readonly SlugRegistryContract $slugs) {}

    /**
     * @return array{
     *     items: array<int, array{uuid: string, title: string, brand: string|null, category: string, gtin: string|null, variant_count: int}>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     last_page: int,
     * }
     */
    public function searchPublishedProducts(
        string $query = '',
        ?string $categoryUuid = null,
        ?string $brandUuid = null,
        int $page = 1,
        int $perPage = 20,
    ): array {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $page = max(1, $page);

        $builder = Product::query()
            ->where('status', ProductStatus::Published->value)
            // Counted rather than loaded: the picker shows "3 varyant", and
            // loading every variant of every result to count them would be the
            // N+1 this module's strict mode exists to catch.
            ->withCount('variants')
            ->with(['brand', 'category']);

        $this->applyText($builder, $query);
        $this->applyCategory($builder, $categoryUuid);
        $this->applyBrand($builder, $brandUuid);

        $paginator = $builder
            // Newest first: a seller browsing without a query is far likelier
            // to want what just arrived than what has sat unsold for a year.
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);

        /** @var array<int, array{uuid: string, title: string, brand: string|null, category: string, gtin: string|null, variant_count: int}> $items */
        $items = $paginator->getCollection()
            ->map(static fn (Product $product): array => [
                'uuid' => $product->uuid,
                'title' => $product->localized('title'),
                'brand' => $product->brand?->name,
                'category' => $product->category->localized('name'),
                'gtin' => $product->gtin,
                'variant_count' => (int) ($product->variants_count ?? 0),
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /**
     * @return array<int, array{uuid: string, sku: string, label: string, is_default: bool}>
     */
    public function variantsForProduct(string $productUuid): array
    {
        $product = Product::query()
            ->where('uuid', $productUuid)
            ->where('status', ProductStatus::Published->value)
            ->first();

        if ($product === null) {
            return [];
        }

        /** @var array<int, array{uuid: string, sku: string, label: string, is_default: bool}> $variants */
        $variants = ProductVariant::query()
            ->where('product_id', $product->getKey())
            // The label is built from these; declared here because strict mode
            // makes the lazy load throw on the first row.
            ->with('attributeValues')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(static fn (ProductVariant $variant): array => [
                'uuid' => $variant->uuid,
                'sku' => $variant->sku,
                'label' => self::labelFor($variant),
                'is_default' => (bool) $variant->is_default,
            ])
            ->values()
            ->all();

        return $variants;
    }

    /**
     * @param array<int, string> $productUuids
     *
     * @return array<string, array{uuid: string, title: string, brand: string|null, category: string, image: string|null, slug: string}>
     */
    public function productSummaries(array $productUuids): array
    {
        $uuids = array_values(array_unique(array_filter($productUuids)));

        if ($uuids === []) {
            return [];
        }

        $summaries = [];

        // PUBLISHED ONLY here as well. A downstream module holding the uuid of a
        // product that has since been archived renders its fallback rather than
        // a title the catalog no longer stands behind.
        foreach (Product::query()
            ->whereIn('uuid', $uuids)
            ->where('status', ProductStatus::Published->value)
            /*
            | `media` IS EAGER-LOADED, NOT REACHED PER ROW. Strict mode throws on a
            | lazy load, and a store page is exactly the shape that would trip it:
            | one product per card, every card asking for its own picture.
            */
            ->with(['brand', 'category', 'media'])
            ->get() as $product) {
            $summaries[$product->uuid] = [
                'uuid' => $product->uuid,
                'title' => $product->localized('title'),
                'brand' => $product->brand?->name,
                'category' => $product->category->localized('name'),
                /*
                | THE SAME CONVERSION THE LISTING CARD USES — `preview` (~620px),
                | not `thumb`: a store card renders near 200px and an upscaled
                | 119px thumbnail is visibly blurry. Null when nobody uploaded
                | anything, so the caller draws its placeholder rather than a
                | broken image.
                */
                'image' => $product->imageUrl('preview'),
                // The canonical slug (ADR-059), so a card links to `/{slug}`
                // rather than to a uuid that 301s to it.
                'slug' => $product->slug,
            ];
        }

        return $summaries;
    }

    /**
     * Added for Order's line snapshot (2026-08-04) — see the contract for why the
     * ancestry travels with it.
     *
     * THE PATH IS ALREADY MATERIALISED (§13.1), so the ancestry costs no extra
     * query: `categories.path` is "/3/17/42/", and the uuids behind those ids come
     * from one `whereIn` over the categories already loaded for this page.
     *
     * @param array<int, string> $productUuids
     *
     * @return array<string, array{brand_uuid: string|null, category_uuid: string|null, category_path_uuids: array<int, string>}>
     */
    public function productClassifications(array $productUuids): array
    {
        $uuids = array_values(array_unique(array_filter($productUuids)));

        if ($uuids === []) {
            return [];
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Product> $products */
        $products = Product::query()
            ->whereIn('uuid', $uuids)
            ->with(['brand', 'category'])
            ->get();

        // Every ancestor id mentioned by any of these products, resolved to uuids
        // in one go rather than per product.
        $ancestorIds = [];

        foreach ($products as $product) {
            foreach ($product->category->ancestorIds() as $id) {
                $ancestorIds[$id] = true;
            }
        }

        /** @var array<int, string> $uuidById */
        $uuidById = $ancestorIds === []
            ? []
            : Category::query()->whereIn('id', array_keys($ancestorIds))->pluck('uuid', 'id')->all();

        $classifications = [];

        foreach ($products as $product) {
            $path = [];

            foreach ($product->category->ancestorIds() as $id) {
                if (isset($uuidById[$id])) {
                    $path[] = $uuidById[$id];
                }
            }

            // Root first, and the category itself last — so a rule scoped to the
            // leaf and one scoped to the root are the same membership test.
            $path[] = $product->category->uuid;

            $classifications[$product->uuid] = [
                'brand_uuid' => $product->brand?->uuid,
                'category_uuid' => $product->category->uuid,
                'category_path_uuids' => $path,
            ];
        }

        return $classifications;
    }

    /**
     * Added for Offer's buy box — see the contract for why a downstream module
     * cannot resolve a slug itself.
     *
     * BY SHAPE, then by the registry. A uuid-looking segment goes straight to the
     * `uuid` column; anything else is a slug and never touches it, because that
     * comparison is `SQLSTATE[22P02]` on PostgreSQL rather than a non-match
     * (ADR-059).
     *
     * ALIASES RESOLVE TOO, deliberately. An inbound link to a product's old
     * address should still show its buy box rather than 404 while the storefront
     * works out the 301 — the alias trail exists precisely so a retired URL keeps
     * answering.
     */
    public function publishedProductUuidFor(string $idOrSlug): ?string
    {
        if (PublicKey::looksLikeUuid($idOrSlug)) {
            $uuid = Product::query()
                ->where('uuid', $idOrSlug)
                ->where('status', ProductStatus::Published->value)
                ->value('uuid');

            return is_string($uuid) ? $uuid : null;
        }

        $match = $this->slugs->resolve($idOrSlug);

        if ($match === null || $match->type !== SluggableType::Product) {
            // A category or brand slug is not a product. Answering with one would
            // let `/products/{brandSlug}/offers` render somebody else's buy box.
            return null;
        }

        $uuid = Product::query()
            ->where('uuid', $match->uuid)
            ->where('status', ProductStatus::Published->value)
            ->value('uuid');

        return is_string($uuid) ? $uuid : null;
    }

    /**
     * @param array<int, string> $variantUuids
     *
     * @return array<string, array{uuid: string, product_uuid: string, sku: string, label: string}>
     */
    public function variantSummaries(array $variantUuids): array
    {
        $uuids = array_values(array_unique(array_filter($variantUuids)));

        if ($uuids === []) {
            return [];
        }

        $summaries = [];

        foreach (ProductVariant::query()
            ->whereIn('uuid', $uuids)
            // Both are read below; strict mode makes a lazy load throw.
            ->with(['attributeValues', 'product'])
            ->get() as $variant) {
            $summaries[$variant->uuid] = [
                'uuid' => $variant->uuid,
                'product_uuid' => $variant->product->uuid,
                'sku' => $variant->sku,
                'label' => self::labelFor($variant),
            ];
        }

        return $summaries;
    }

    /**
     * "Kırmızı / M" — the combination a human recognises.
     *
     * A one-variant product (ADR-039: a "simple" product is a one-variant
     * product) has no axes at all, so it falls back to the SKU rather than
     * rendering an empty string where a label belongs.
     */
    private static function labelFor(ProductVariant $variant): string
    {
        $parts = $variant->attributeValues
            ->map(static fn ($value): string => (string) $value->localized('label'))
            ->filter(static fn (string $label): bool => $label !== '')
            ->values()
            ->all();

        return $parts === [] ? $variant->sku : implode(' / ', $parts);
    }

    /**
     * Free text across the title columns, the GTIN and the SKU.
     *
     * A leading-wildcard `LIKE`, which no index helps — see the class docblock
     * for why that trade is deliberate at this catalog size.
     *
     * CASE FOLDING IS THE DRIVER'S JOB, and the two drivers differ. Postgres
     * gets `ILIKE`, which folds Turkish correctly, so `istanbul` finds
     * `İSTANBUL`. SQLite — the test suite — has only `LIKE`, which is
     * case-insensitive for ASCII and exact for everything else; `LOWER()` does
     * not rescue it, because SQLite's is ASCII-only too and would leave `Ü`
     * untouched while `mb_strtolower` lowered the needle, matching nothing.
     * Branching on the driver is honest about that; folding in PHP would be a
     * silent lie on one of the two.
     *
     * @param Builder<Product> $builder
     */
    private function applyText(Builder $builder, string $query): void
    {
        $term = trim($query);

        if ($term === '') {
            return;
        }

        $like = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $needle = '%'.$term.'%';

        $builder->where(function (Builder $scoped) use ($like, $needle, $term): void {
            /*
            | The column name is composed from `catalog.locales`, which is a
            | SCHEMA concern living in code precisely because adding a locale is
            | a migration (config/catalog.php) — not user input, and not
            | reachable from a request. The value is still bound.
            */
            foreach (config('catalog.locales', ['tr']) as $locale) {
                $scoped->orWhereRaw("title_{$locale} {$like} ?", [$needle]);
            }

            // Exact, not fuzzy: a barcode or a SKU is either the one you have in
            // your hand or it is a different product entirely.
            $scoped->orWhere('gtin', $term)
                ->orWhereHas('variants', static fn (Builder $variants) => $variants->where('sku', $term));
        });
    }

    /**
     * @param Builder<Product> $builder
     */
    private function applyCategory(Builder $builder, ?string $categoryUuid): void
    {
        if ($categoryUuid === null) {
            return;
        }

        $category = Category::query()->where('uuid', $categoryUuid)->first();

        if ($category === null) {
            // A filter naming nothing must return nothing, never everything.
            $builder->whereRaw('1 = 0');

            return;
        }

        // The subtree, via the materialised path (Catalog §13.1) — one prefix
        // scan, no recursion. Picking a department finds what is filed beneath
        // it, which is how a seller thinks about the taxonomy.
        $ids = Category::query()
            ->where('path', 'like', $category->path.'%')
            ->pluck('id')
            ->all();

        $builder->whereIn('category_id', $ids);
    }

    /**
     * @param Builder<Product> $builder
     */
    private function applyBrand(Builder $builder, ?string $brandUuid): void
    {
        if ($brandUuid === null) {
            return;
        }

        $brandId = Brand::query()->where('uuid', $brandUuid)->value('id');

        if ($brandId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where('brand_id', $brandId);
    }
}
