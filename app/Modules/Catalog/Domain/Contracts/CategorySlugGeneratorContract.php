<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contracts;

/**
 * Produces the globally-unique, stable slugs catalog URLs are built from (§3.5).
 *
 * ONE CONTRACT FOR BOTH category and product slugs, because the rule is the
 * same for both — slugify, then suffix until globally unique — and two
 * interfaces with one method each would be ceremony. The implementation asks the
 * relevant repository, so the aggregates never check uniqueness themselves.
 *
 * Slugs are SEO surface: they must be stable, which is why nothing regenerates
 * one on an ordinary edit.
 *
 * @see App\Modules\Catalog\Infrastructure\Generators\DefaultCatalogSlugGenerator
 */
interface CategorySlugGeneratorContract
{
    /**
     * A globally-unique category slug derived from the requested handle.
     */
    public function forCategory(string $requested, ?int $exceptId = null): string;

    /**
     * A globally-unique product slug derived from the requested handle.
     */
    public function forProduct(string $requested, ?int $exceptId = null): string;
}
