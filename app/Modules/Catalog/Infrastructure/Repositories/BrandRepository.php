<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Repositories;

use App\Modules\Catalog\Domain\Contracts\BrandRepositoryContract;
use App\Modules\Catalog\Domain\Models\Brand;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Eager-loads `media` on every read: the brand logo is rendered wherever a brand
 * is listed, and strict mode makes the lazy load throw rather than quietly
 * issuing one query per row.
 *
 * @see App\Modules\Catalog\Domain\Contracts\BrandRepositoryContract
 */
final class BrandRepository implements BrandRepositoryContract
{
    /**
     * @var list<string>
     */
    private array $with = ['media'];

    public function findByUuid(string $uuid): ?Brand
    {
        return Brand::query()->with($this->with)->where('uuid', $uuid)->first();
    }

    public function findOrFailByUuid(string $uuid): Brand
    {
        $brand = $this->findByUuid($uuid);

        if ($brand === null) {
            throw (new ModelNotFoundException)->setModel(Brand::class, [$uuid]);
        }

        return $brand;
    }

    public function findBySlug(string $slug): ?Brand
    {
        return Brand::query()->with($this->with)->where('slug', $slug)->first();
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return Brand::query()
            ->where('slug', $slug)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    /**
     * @return Collection<int, Brand>
     */
    public function active(): Collection
    {
        return Brand::query()->with($this->with)->active()->orderBy('name')->get();
    }
}
