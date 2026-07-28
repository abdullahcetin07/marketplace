<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Contracts\BrandRepositoryContract;
use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\DTOs\CreateBrandDTO;
use App\Modules\Catalog\Domain\Events\BrandCreated;
use App\Modules\Catalog\Domain\Models\Brand;
use Illuminate\Support\Str;

/**
 * Adds a brand (§2.2).
 *
 * Platform-owned like the taxonomy: a seller picks a brand, never invents one.
 * Two spellings of "Samsung" split every brand filter and every brand page, and
 * merging them afterwards is manual work on live data.
 */
final class CreateBrandAction extends BaseAction
{
    public function __construct(private readonly BrandRepositoryContract $brands) {}

    public function handle(mixed ...$arguments): Brand
    {
        /** @var CreateBrandDTO $data */
        $data = $arguments[0];

        return Brand::create([
            'name' => $data->name,
            'slug' => $this->uniqueSlug($data->slug ?? $data->name),
            'is_active' => $data->isActive,
        ]);
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Brand $result */
        BrandCreated::dispatch($result->getKey(), $result->uuid, $result->name, $result->slug);
    }

    /**
     * Brands are few and created rarely, so the slug rule lives here rather
     * than behind the catalog slug contract — that contract exists for the two
     * slugs that are public URL segments (category, product) and whose policy
     * is expected to grow.
     */
    private function uniqueSlug(string $requested): string
    {
        $base = Str::slug($requested);
        $base = $base === '' ? 'brand' : $base;

        $slug = $base;
        $suffix = 2;

        while ($this->brands->slugExists($slug)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
