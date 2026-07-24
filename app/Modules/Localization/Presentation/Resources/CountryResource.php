<?php

declare(strict_types=1);

namespace App\Modules\Localization\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A country, as the public API exposes it.
 *
 * The country picker on checkout consumes this. `code` is the ISO-2 — clients
 * speak ISO, and a code survives a reseed where an id does not.
 *
 * @extends BaseResource<\App\Modules\Localization\Domain\Models\Country>
 */
final class CountryResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'code' => $this->resource->iso2,
            'iso3' => $this->resource->iso3,
            'name' => $this->resource->name,
            'native_name' => $this->resource->native_name,
            'phone_code' => $this->resource->dialPrefix(),
            'flag' => $this->resource->flag,
            // whenLoaded, not the relation directly: strict mode makes a lazy
            // load throw, and a resource is the easiest place to trigger one.
            'currency' => $this->whenLoaded('currency', fn (): ?string => $this->resource->currency?->code),
        ];
    }
}
