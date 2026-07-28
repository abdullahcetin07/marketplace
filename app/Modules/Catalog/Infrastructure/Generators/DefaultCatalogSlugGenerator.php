<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Generators;

use App\Modules\Catalog\Domain\Contracts\CategoryRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\CategorySlugGeneratorContract;
use App\Modules\Catalog\Domain\Contracts\ProductRepositoryContract;
use Illuminate\Support\Str;

/**
 * Slugifies the requested handle and suffixes it until globally unique.
 *
 * Uniqueness is asked of the repositories — the aggregates never check it —
 * which is what keeps the slug policy swappable behind the contract, exactly as
 * Store does it.
 *
 * `Str::slug` transliterates Turkish characters ("Kadın Giyim" → "kadin-giyim"),
 * which is what a URL needs; the localized name columns keep the real spelling.
 *
 * @see App\Modules\Catalog\Domain\Contracts\CategorySlugGeneratorContract
 */
final class DefaultCatalogSlugGenerator implements CategorySlugGeneratorContract
{
    public function __construct(
        private readonly CategoryRepositoryContract $categories,
        private readonly ProductRepositoryContract $products,
    ) {}

    public function forCategory(string $requested, ?int $exceptId = null): string
    {
        return $this->unique(
            $requested,
            'category',
            fn (string $slug): bool => $this->categories->slugExists($slug, $exceptId),
        );
    }

    public function forProduct(string $requested, ?int $exceptId = null): string
    {
        return $this->unique(
            $requested,
            'product',
            fn (string $slug): bool => $this->products->slugExists($slug, $exceptId),
        );
    }

    /**
     * @param  callable(string): bool  $taken
     */
    private function unique(string $requested, string $fallback, callable $taken): string
    {
        $base = Str::slug($requested);

        // A title of nothing but non-transliterable characters slugs to the
        // empty string, which would produce a URL of `//`. The fallback is
        // ugly but addressable, and the suffix loop makes it unique.
        if ($base === '') {
            $base = $fallback;
        }

        $slug = $base;
        $suffix = 2;

        while ($taken($slug)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
