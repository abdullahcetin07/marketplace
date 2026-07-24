<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Domain\Storefront\StorefrontContributorContract;

/**
 * The registry of modules that enrich the public storefront (ADR-036).
 *
 * The composition counterpart to `PermissionRegistry`: a module adds itself to
 * the storefront in its own service provider —
 * `StorefrontRegistry::register(ProductStorefrontContributor::class)` — and Store's
 * public assembler resolves and invokes each. Store never names a contributor;
 * the dependency points from the contributor to this Core seam, not from Store to
 * the module. Class-strings only (resolved through the container at assemble time)
 * so registering does not eagerly build anything.
 *
 * @see App\Core\Domain\Storefront\StorefrontContributorContract
 */
final class StorefrontRegistry
{
    /**
     * Registered contributor class-strings, keyed by class name to dedupe.
     *
     * @var array<string, class-string<StorefrontContributorContract>>
     */
    private static array $contributors = [];

    /**
     * @param  class-string<StorefrontContributorContract>  $contributor
     */
    public static function register(string $contributor): void
    {
        self::$contributors[$contributor] = $contributor;
    }

    /**
     * @return array<int, class-string<StorefrontContributorContract>>
     */
    public static function contributors(): array
    {
        return array_values(self::$contributors);
    }

    /**
     * Reset — for tests that register a contributor and must not leak it.
     */
    public static function flush(): void
    {
        self::$contributors = [];
    }
}
