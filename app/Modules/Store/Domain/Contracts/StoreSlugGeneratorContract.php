<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Contracts;

/**
 * Produces a storefront slug — the global handle in the store's public path
 * `/store/{slug}` (ADR-035).
 *
 * A CONTRACT so the strategy is replaceable without touching the Store aggregate
 * or the creation action. Today's rule is "slugify the requested name, suffix
 * until globally unique"; tomorrow's may add reserved-slug lists, per-locale
 * transliteration, or profanity filtering — all behind this seam.
 *
 * @see App\Modules\Store\Infrastructure\Generators\DefaultStoreSlugGenerator
 */
interface StoreSlugGeneratorContract
{
    /**
     * A globally-unique slug derived from the requested handle.
     */
    public function generate(string $requested): string;
}
