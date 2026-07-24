<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * An invitation — never the token.
 *
 * @extends BaseResource<\App\Modules\Organization\Domain\Models\OrganizationInvitation>
 */
final class OrganizationInvitationResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'email' => $this->resource->email,
            'role' => $this->resource->role->value,
            'status' => $this->resource->status->value,
            'expires_at' => $this->resource->expires_at?->toIso8601String(),
            // token_hash is $hidden and never surfaced (ADR-025/031).
            ...$this->timestamps(),
        ];
    }
}
