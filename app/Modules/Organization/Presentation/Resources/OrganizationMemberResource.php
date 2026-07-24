<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A membership row.
 *
 * @extends BaseResource<\App\Modules\Organization\Domain\Models\OrganizationMember>
 */
final class OrganizationMemberResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'role' => $this->resource->role->value,
            'status' => $this->resource->status->value,
            'user' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->resource->user?->uuid,
                'name' => $this->resource->user?->display_name,
                'email' => $this->resource->user?->email,
            ]),
            'joined_at' => $this->resource->joined_at?->toIso8601String(),
            ...$this->timestamps(),
        ];
    }
}
