<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A session on the user's security page.
 *
 * `is_current` is what makes the page usable — the UI must not offer a revoke
 * button that logs the user out of the tab they are looking at.
 *
 * Neither the framework session id nor the token id is ever emitted. They are
 * live credentials; the UUID is what the revoke endpoint accepts.
 *
 * @extends BaseResource<\App\Modules\Identity\Domain\Models\UserSession>
 */
final class SessionResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'guard' => $this->resource->guard,

            // Truncated to /24: enough for a user to recognise "that was my
            // office", not enough to be a precise location record.
            'ip_address' => $this->maskIp($this->resource->ip_address),
            'location' => $this->resource->location,

            'device' => $this->whenLoaded('device', fn (): ?array => $this->resource->device === null ? null : [
                'id' => $this->resource->device->uuid,
                'label' => $this->resource->device->label(),
                'platform' => $this->resource->device->platform,
                'browser' => $this->resource->device->browser,
                'type' => $this->resource->device->device_type,
                'trusted' => $this->resource->device->isTrusted(),
            ]),

            'is_current' => $this->resource->isCurrent(),
            'is_active' => $this->resource->isActive(),

            'last_activity_at' => $this->resource->last_activity_at?->toIso8601String(),
            'expires_at' => $this->resource->expires_at?->toIso8601String(),

            ...$this->timestamps(),
        ];
    }

    private function maskIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        if (str_contains($ip, ':')) {
            // IPv6 — keep the routing prefix only.
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 3)).'::';
        }

        $parts = explode('.', $ip);

        return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.{$parts[2]}.x" : $ip;
    }
}
