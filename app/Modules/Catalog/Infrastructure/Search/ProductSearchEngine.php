<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Search;

use App\Modules\Catalog\Domain\Contracts\ProductSearchContract;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The typo-tolerant engine in front of the catalogue's free-text search
 * (ADR-090), and the one place that knows it might not be there.
 *
 * **IT ANSWERS `null`, NOT AN EMPTY LIST, WHEN IT CANNOT ANSWER.** That
 * distinction is the whole resilience design: an empty array means "the engine
 * looked and found nothing", `null` means "there was no engine" — and the
 * caller turns `null` into the Tier-1 folded `LIKE` (ADR-089's `search_text`)
 * rather than into an empty results page. Search degrades to worse search, never
 * to no search.
 *
 * **The engine is skipped, quietly and on purpose, when Scout has no driver.**
 * That is the rollout order the work order asks for: ship the code with
 * `SCOUT_DRIVER=null`, serve every query from the fallback, then turn the engine
 * on. A null-driver Scout would otherwise answer every search with zero hits,
 * which looks exactly like a catalogue that sells nothing.
 *
 * **NO PRICE AND NO STOCK CROSS THIS LINE.** The engine ranks what a product IS;
 * which seller has it and for how much stays in Offer, and the listing applies
 * that afterwards (ADR-037/090, `CatalogBoundaryTest`).
 */
final class ProductSearchEngine implements ProductSearchContract
{
    /**
     * How deep the ranked set goes.
     *
     * A listing shows at most 48 per page, so this is roughly ten pages of
     * relevance. Past it the tail is cut — stated here rather than discovered,
     * because a silent cap reads as "that is everything".
     */
    private const RANKED_LIMIT = 500;

    /**
     * Product uuids in relevance order, or null when the engine cannot answer.
     *
     * @return array<int, string>|null
     */
    public function rankedUuids(string $query, int $limit = self::RANKED_LIMIT): ?array
    {
        if (trim($query) === '' || ! $this->enabled()) {
            return null;
        }

        try {
            /** @var array<int, string> $keys */
            $keys = Product::search($query)->take($limit)->keys()->all();

            return array_values(array_map('strval', $keys));
        } catch (Throwable $e) {
            /*
            | WARNING, NOT ERROR, AND NEVER A THROW. The shopper is about to get
            | a worse but working search; what the log has to carry is that it
            | happened at all, because the only other symptom is results getting
            | quietly dumber.
            */
            Log::warning('Search engine unavailable, falling back to the database fold', [
                'query' => $query,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Prefix search for the as-you-type box.
     *
     * Brands and categories come out of the PRODUCT hits rather than from
     * indexes of their own: the product document already carries both names and
     * Meilisearch searches them, so a brand a shopper is typing surfaces through
     * its products. The cost is that a brand with no matching product never
     * appears, which for a catalogue where every brand has products is a
     * distinction without a difference — and it keeps two more indexes from
     * needing to be kept in sync.
     *
     * @return array{products: array<int, array<string, string>>, brands: array<int, string>, categories: array<int, string>}|null
     */
    public function suggest(string $query, int $products = 6, int $brands = 4, int $categories = 4): ?array
    {
        if (trim($query) === '' || ! $this->enabled()) {
            return null;
        }

        try {
            /** @var \Illuminate\Support\Collection<int, Product> $hits */
            $hits = Product::search($query)->take(max($products, $brands, $categories) * 4)->get();
        } catch (Throwable $e) {
            Log::warning('Search engine unavailable for suggestions', [
                'query' => $query,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return [
            'products' => $hits->take($products)
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
                ->take($brands)
                ->values()
                ->all(),
            'categories' => $hits->map(static fn (Product $product): string => $product->category->localized('name'))
                ->filter()
                ->unique()
                ->take($categories)
                ->values()
                ->all(),
        ];
    }

    /**
     * Whether the engine is reachable right now — the health signal.
     *
     * A real query rather than a version ping: an engine that answers `/health`
     * while its index is missing is down for every purpose this platform has.
     */
    public function isAvailable(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            Product::search('')->take(1)->keys();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Scout configured with a real engine. `null` and `collection` are the
     * drivers that mean "no engine here" — the first in production before the
     * rollout, the second in a test that has not opted in.
     */
    public function enabled(): bool
    {
        return ! in_array((string) config('scout.driver'), ['null', '', 'collection'], true);
    }
}
