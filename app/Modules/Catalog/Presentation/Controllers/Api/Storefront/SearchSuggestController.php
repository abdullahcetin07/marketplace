<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers\Api\Storefront;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Catalog\Domain\Contracts\ProductSearchContract;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Support\TurkishFold;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * As-you-type suggestions for the storefront's search box (ADR-090).
 *
 * **DATA ONLY — THE DROP-DOWN IS THE STOREFRONT'S** (ADR-058). This returns the
 * top products, brands and categories for a prefix; debouncing, keyboard
 * navigation and the empty state belong to the Next.js side.
 *
 * **NO PRICE, HERE OF ALL PLACES.** A suggestion list is the most tempting spot
 * to inline "₺249,90", and the price belongs to an Offer with as many values as
 * there are sellers (ADR-037). The storefront overlays it from the offer
 * endpoint, the same as the listing does.
 *
 * **IT DEGRADES INSTEAD OF DISAPPEARING.** With the engine off or unreachable the
 * suggestions come from the Tier-1 folded `search_text` prefix — no typo
 * tolerance and no ranking, but a shopper still sees their catalogue. Brands and
 * categories are then derived from those same rows.
 */
final class SearchSuggestController extends BaseController
{
    private const PRODUCTS = 6;

    private const BRANDS = 4;

    private const CATEGORIES = 4;

    public function __construct(private readonly ProductSearchContract $search) {}

    /**
     * GET /api/v1/search/suggest?q=
     */
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            // An empty box suggests nothing. Returning "popular searches" here
            // would be inventing data the platform does not collect yet.
            return $this->ok($this->empty());
        }

        $suggestions = $this->search->suggest($query, self::PRODUCTS, self::BRANDS, self::CATEGORIES);

        return $this->ok($suggestions ?? $this->fromFold($query));
    }

    /**
     * @return array{products: array<int, array<string, string>>, brands: array<int, string>, categories: array<int, string>}
     */
    private function empty(): array
    {
        return ['products' => [], 'brands' => [], 'categories' => []];
    }

    /**
     * The fallback: the same folded column the listing falls back to.
     *
     * @return array{products: array<int, array<string, string>>, brands: array<int, string>, categories: array<int, string>}
     */
    private function fromFold(string $query): array
    {
        $tokens = TurkishFold::tokens($query);

        if ($tokens === []) {
            return $this->empty();
        }

        $builder = Product::query()
            ->where('status', ProductStatus::Published->value)
            ->where('is_sellable', true)
            ->with(['brand', 'category']);

        foreach ($tokens as $token) {
            $builder->where('search_text', 'LIKE', '%'.$token.'%');
        }

        // Enough rows to fill every list, not enough to be a listing.
        $hits = $builder->orderByDesc('published_at')->limit(self::PRODUCTS * 4)->get();

        return [
            'products' => $hits->take(self::PRODUCTS)
                ->map(static fn (Product $product): array => [
                    'uuid' => $product->uuid,
                    'title' => $product->localized('title'),
                    'slug' => (string) $product->slug,
                ])
                ->values()
                ->all(),
            'brands' => $hits->map(static fn (Product $product): ?string => $product->brand?->name)
                ->filter()
                ->unique()
                ->take(self::BRANDS)
                ->values()
                ->all(),
            'categories' => $hits->map(static fn (Product $product): string => $product->category->localized('name'))
                ->filter()
                ->unique()
                ->take(self::CATEGORIES)
                ->values()
                ->all(),
        ];
    }
}
