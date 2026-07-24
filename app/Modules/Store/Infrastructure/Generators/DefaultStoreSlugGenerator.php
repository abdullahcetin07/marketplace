<?php

declare(strict_types=1);

namespace App\Modules\Store\Infrastructure\Generators;

use App\Modules\Store\Domain\Contracts\StoreRepositoryContract;
use App\Modules\Store\Domain\Contracts\StoreSlugGeneratorContract;
use Illuminate\Support\Str;

/**
 * Slugifies the requested handle and suffixes it until globally unique.
 *
 * A slug is the store's public path handle (`/store/{slug}`, ADR-035), so
 * uniqueness is platform-wide, not per-organization. Uniqueness is asked of the
 * repository — the aggregate never checks it — which is what keeps the
 * numbering/slug policy swappable behind the contract.
 *
 * @see App\Modules\Store\Domain\Contracts\StoreSlugGeneratorContract
 */
final class DefaultStoreSlugGenerator implements StoreSlugGeneratorContract
{
    public function __construct(private readonly StoreRepositoryContract $stores) {}

    public function generate(string $requested): string
    {
        $base = Str::slug($requested);

        if ($base === '') {
            $base = 'store';
        }

        $slug = $base;
        $suffix = 2;

        while ($this->stores->slugExists($slug)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
