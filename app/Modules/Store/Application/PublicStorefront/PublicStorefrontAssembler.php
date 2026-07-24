<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\PublicStorefront;

use App\Core\Domain\Storefront\StorefrontContext;
use App\Core\Domain\Storefront\StorefrontContributorContract;
use App\Core\Support\StorefrontRegistry;
use App\Modules\Store\Domain\Models\Store;
use Throwable;

/**
 * Composes the extension sections of a public storefront (ADR-036).
 *
 * Store owns the storefront CORE (the resource formats that); this gathers what
 * OTHER modules contribute. It resolves each registered contributor, hands it a
 * scalar `StorefrontContext`, and collects its section under its `key()`.
 *
 * RESILIENT BY CONTRACT: a contributor that throws is reported and its section
 * omitted — a broken Reviews module must never take down the storefront core
 * (ADR-036). Store names no contributor; the registry holds class-strings.
 */
final class PublicStorefrontAssembler
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function extensionsFor(Store $store): array
    {
        $context = $this->contextFor($store);
        $sections = [];

        foreach (StorefrontRegistry::contributors() as $class) {
            try {
                /** @var StorefrontContributorContract $contributor */
                $contributor = app($class);
                $sections[$contributor->key()] = $contributor->contribute($context);
            } catch (Throwable $exception) {
                // Degrade to omitting this section — never break the core store.
                report($exception);
            }
        }

        return $sections;
    }

    private function contextFor(Store $store): StorefrontContext
    {
        return new StorefrontContext(
            storeId: $store->getKey(),
            storeUuid: $store->uuid,
            slug: $store->slug,
            organizationId: $store->organization_id,
            languageCode: (string) $store->defaultLanguage?->code,
            currencyCode: (string) $store->defaultCurrency?->code,
        );
    }
}
