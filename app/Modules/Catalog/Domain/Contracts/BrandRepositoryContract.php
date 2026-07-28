<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contracts;

use App\Modules\Catalog\Domain\Models\Brand;
use Illuminate\Database\Eloquent\Collection;

/**
 * The persistence port for brands (§2.2).
 *
 * @see App\Modules\Catalog\Infrastructure\Repositories\BrandRepository
 */
interface BrandRepositoryContract
{
    public function findByUuid(string $uuid): ?Brand;

    public function findOrFailByUuid(string $uuid): Brand;

    public function findBySlug(string $slug): ?Brand;

    public function slugExists(string $slug, ?int $exceptId = null): bool;

    /**
     * @return Collection<int, Brand>
     */
    public function active(): Collection;
}
