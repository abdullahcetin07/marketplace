<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * An organization, as the API exposes it.
 *
 * @extends BaseResource<\App\Modules\Organization\Domain\Models\Organization>
 */
final class OrganizationResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'legal_name' => $this->resource->legal_name,
            'display_name' => $this->resource->display_name,
            'slug' => $this->resource->slug,
            'status' => $this->resource->status->value,
            'plan' => $this->whenLoaded('plan', fn (): ?string => $this->resource->plan?->slug),
            // The resolved allowance and how much is left (null = unlimited).
            'store_limit' => $this->resource->effectiveStoreLimit(),
            'remaining_store_slots' => $this->resource->remainingStoreSlots(),
            'country' => $this->whenLoaded('country', fn (): ?string => $this->resource->country?->iso2),
            'currency' => $this->whenLoaded('currency', fn (): ?string => $this->resource->currency?->code),
            'verified' => $this->resource->verified_at !== null,
            ...$this->timestamps(),
        ];
    }
}
