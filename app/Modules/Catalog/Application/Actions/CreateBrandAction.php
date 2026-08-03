<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Contracts\SlugRegistryContract;
use App\Modules\Catalog\Domain\DTOs\CreateBrandDTO;
use App\Modules\Catalog\Domain\Enums\SluggableType;
use App\Modules\Catalog\Domain\Events\BrandCreated;
use App\Modules\Catalog\Domain\Models\Brand;

/**
 * Adds a brand (§2.2).
 *
 * Platform-owned like the taxonomy: a seller picks a brand, never invents one.
 * Two spellings of "Samsung" split every brand filter and every brand page, and
 * merging them afterwards is manual work on live data.
 *
 * THE SLUG NOW COMES FROM THE GLOBAL REGISTRY (ADR-059). This action used to own
 * a private `uniqueSlug()` that asked only `brands.slug_exists`, with a comment
 * explaining that brand slugs were not public URL segments and so did not need
 * the shared policy. The flat scheme made them public URL segments — `/bioderma`
 * is a brand page — so a brand competing with a category for the same name is now
 * two pages at one address, and only a namespace that sees all three can refuse
 * it.
 */
final class CreateBrandAction extends BaseAction
{
    public function __construct(private readonly SlugRegistryContract $slugs) {}

    public function handle(mixed ...$arguments): Brand
    {
        /** @var CreateBrandDTO $data */
        $data = $arguments[0];

        return Brand::create([
            'name' => $data->name,
            // Registered by `HasRegisteredSlug` on save, once the row has an id.
            'slug' => $this->slugs->issue($data->slug ?? $data->name, SluggableType::Brand),
            'is_active' => $data->isActive,
        ]);
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Brand $result */
        BrandCreated::dispatch($result->getKey(), $result->uuid, $result->name, $result->slug);
    }
}
