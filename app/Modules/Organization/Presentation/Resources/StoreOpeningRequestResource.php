<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A Store Opening Request.
 *
 * @extends BaseResource<\App\Modules\Organization\Domain\Models\StoreOpeningRequest>
 */
final class StoreOpeningRequestResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'status' => $this->resource->status->value,
            'store_name' => $this->resource->store_name,
            'slug' => $this->resource->slug,
            'category_id' => $this->resource->category_id,
            'description' => $this->resource->description,
            'reason' => $this->resource->reason,
            'admin_notes' => $this->resource->admin_notes,
            // The store the Store module created from this request, if any.
            'created_store_uuid' => $this->resource->created_store_uuid,
            'submitted_at' => $this->resource->submitted_at?->toIso8601String(),
            'approved_at' => $this->resource->approved_at?->toIso8601String(),
            'rejected_at' => $this->resource->rejected_at?->toIso8601String(),
            ...$this->timestamps(),
        ];
    }
}
