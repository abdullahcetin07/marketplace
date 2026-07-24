<?php

declare(strict_types=1);

namespace App\Core\Domain\Storefront;

/**
 * The scalar context handed to every storefront contributor (ADR-036).
 *
 * Carries only what a contributing module needs to fetch its own slice —
 * identifiers and locale codes, never a Store model — so a future Product or
 * Review module enriches the storefront without importing Store (ADR-033).
 *
 * @see App\Core\Domain\Storefront\StorefrontContributorContract
 */
final class StorefrontContext
{
    public function __construct(
        public readonly int $storeId,
        public readonly string $storeUuid,
        public readonly string $slug,
        public readonly int $organizationId,
        public readonly string $languageCode,
        public readonly string $currencyCode,
    ) {}
}
