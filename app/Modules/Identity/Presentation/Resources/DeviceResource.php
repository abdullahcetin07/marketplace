<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A device on the user's security page.
 *
 * The platform prioritises usability: a device is identified by WHAT IT IS, not
 * by a name the user had to invent. The list carries the four signals a person
 * actually recognises a device from:
 *
 *   os · browser · approximate location · last seen
 *
 * plus a ready-made `label` ("Chrome on Windows") and its trust state.
 *
 * THE FINGERPRINT IS NEVER EMITTED. It is an internal correlation value; a user
 * has no use for it and exposing it would leak the signal it is built from.
 *
 * @extends BaseResource<\App\Modules\Identity\Domain\Models\UserDevice>
 */
final class DeviceResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),

            // The four identification signals.
            'os' => $this->resource->platform,
            'browser' => $this->resource->browser,
            // Coarse, city-level. Null until a geo-IP listener is configured.
            'location' => $this->resource->location,
            'last_seen_at' => $this->resource->last_used_at?->toIso8601String(),

            // Convenience summary and metadata.
            'label' => $this->resource->label(),
            'type' => $this->resource->device_type,

            // isTrusted() applies the expiry window — a device trusted 40 days
            // ago reads as untrusted, which is the honest answer.
            'trusted' => $this->resource->isTrusted(),
            'trusted_at' => $this->resource->trusted_at?->toIso8601String(),

            // Masked to /24 — a fallback identification cue when location is
            // not yet populated, without being a precise tracking record.
            'last_ip' => $this->maskIp($this->resource->last_ip),

            ...$this->timestamps(),
        ];
    }

    private function maskIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 3)).'::';
        }

        $parts = explode('.', $ip);

        return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.{$parts[2]}.x" : $ip;
    }
}
