<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A user, as the API exposes them.
 *
 * `id` is the UUID. The internal bigint never appears in a response —
 * @see docs/001_Architecture.md §8.
 *
 * Locale relations are emitted as CODES, not ids, for the same reason: the
 * client speaks ISO, and codes survive a reseed.
 *
 * @extends BaseResource<\App\Models\User>
 */
final class UserResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'type' => $this->resource->type->value,
            'first_name' => $this->resource->first_name,
            'last_name' => $this->resource->last_name,
            // Computed, never a column (ADR-012). Emitted so a client never
            // has to reimplement the join rule.
            'display_name' => $this->resource->display_name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'avatar_url' => $this->resource->avatar_url,
            'status' => $this->resource->status->value,

            // whenLoaded, not the relation directly: strict mode makes a lazy
            // load throw, and a resource is the easiest place to trigger one.
            'language' => $this->whenLoaded('language', fn (): ?string => $this->resource->language?->code),
            'country' => $this->whenLoaded('country', fn (): ?string => $this->resource->country?->iso2),
            'currency' => $this->whenLoaded('currency', fn (): ?string => $this->resource->currency?->code),
            'timezone' => $this->whenLoaded('timezone', fn (): ?string => $this->resource->timezone?->name),

            'email_verified' => $this->resource->email_verified_at !== null,
            'two_factor_enabled' => $this->resource->hasTwoFactorEnabled(),

            /*
            | Sign-in metadata is shown to the account owner and to staff
            | holding the permission — it is security-relevant to the former
            | and support context for the latter, but not public.
            */
            'last_login_at' => $this->when(
                $this->isSelf($request),
                fn (): ?string => $this->resource->last_login_at?->toIso8601String(),
            ),
            'login_count' => $this->when($this->isSelf($request), $this->resource->login_count),

            // Permissions the frontend needs to decide what to render. Roles
            // are deliberately NOT exposed — the client should branch on
            // capability, never on role name.
            'permissions' => $this->when(
                $this->isSelf($request),
                fn (): array => $this->resource->getAllPermissions()->pluck('name')->all(),
            ),

            ...$this->timestamps(),
        ];
    }

    /**
     * Whether the requester is the user being rendered.
     */
    private function isSelf(Request $request): bool
    {
        $actor = current_actor();

        return $actor !== null && $actor->getKey() === $this->resource->getKey();
    }
}
