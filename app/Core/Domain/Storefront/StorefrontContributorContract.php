<?php

declare(strict_types=1);

namespace App\Core\Domain\Storefront;

/**
 * A module's contribution to the public storefront (ADR-036).
 *
 * The composition seam. Store owns the storefront CORE (identity, branding, SEO,
 * contact, locale); any other module that wants to appear on a storefront —
 * Product, Category, Campaign, Review, Statistics — implements this and registers
 * it via `StorefrontRegistry`. Store assembles the core and merges each
 * contributor's output under `extensions[key()]`. Store depends on none of them.
 *
 * Contract for implementers:
 *   - `key()` is a stable, namespaced section name (e.g. `products`) — it becomes
 *     a key in the public `extensions` object, so it must not change once shipped.
 *   - `contribute()` returns the section's PUBLIC data only, as an allow-list
 *     array. It must be cheap (it runs on every public request) and must never
 *     throw to break the core storefront — return an empty section instead.
 *
 * @see App\Core\Domain\Storefront\StorefrontContext
 * @see docs/modules/Store.md §12
 */
interface StorefrontContributorContract
{
    /**
     * The stable, namespaced section key under `extensions`.
     */
    public function key(): string;

    /**
     * The section's public payload for this store.
     *
     * @return array<string, mixed>
     */
    public function contribute(StorefrontContext $context): array;
}
