<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Generators;

use App\Modules\Catalog\Domain\Contracts\CategorySlugGeneratorContract;
use App\Modules\Catalog\Domain\Contracts\SlugRegistryContract;
use App\Modules\Catalog\Domain\Enums\SluggableType;

/**
 * Slugifies the requested handle and suffixes it until globally unique.
 *
 * UNIQUENESS MOVED TO THE REGISTRY (ADR-059) and this became a two-line adapter.
 * It used to ask each entity's own repository — `categories.slug_exists`,
 * `products.slug_exists` — which was right while the two lived under different URL
 * prefixes and could each own their namespace. The flat scheme ended that: a
 * category and a brand both addressed at the root cannot each check only their own
 * table, or the second one wins the URL and the first one disappears.
 *
 * KEPT RATHER THAN DELETED, so the four actions that already depend on
 * `CategorySlugGeneratorContract` do not all change to say the same thing a
 * different way. The contract's promise — "a globally-unique slug for this
 * handle" — is unchanged; what "globally" means got bigger.
 *
 * @see App\Modules\Catalog\Domain\Contracts\CategorySlugGeneratorContract
 * @see App\Modules\Catalog\Infrastructure\Registries\SlugRegistry
 */
final class DefaultCatalogSlugGenerator implements CategorySlugGeneratorContract
{
    public function __construct(private readonly SlugRegistryContract $registry) {}

    public function forCategory(string $requested, ?int $exceptId = null): string
    {
        return $this->registry->issue($requested, SluggableType::Category, $exceptId);
    }

    public function forProduct(string $requested, ?int $exceptId = null): string
    {
        return $this->registry->issue($requested, SluggableType::Product, $exceptId);
    }
}
