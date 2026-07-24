<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One sign-in attempt, as shown on a user's login history.
 *
 * STAFF-FACING and permission-gated (`user.view_login_history`), so the IP and
 * the true failure reason ARE exposed — that is the context support needs to
 * answer "was this me?" The attempted password is never stored, so it can never
 * leak here.
 *
 * No `id`/uuid: a login attempt is an append-only log row, not an addressable
 * resource. There is nothing to link to.
 *
 * @extends BaseResource<\App\Modules\Identity\Domain\Models\LoginAttempt>
 */
final class LoginAttemptResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'successful' => (bool) $this->resource->successful,
            // Null on success; the real reason on failure (suspended, unverified,
            // invalid) — staff context, never shown to the account owner's own
            // client, which only ever sees INVALID_CREDENTIALS.
            'failure_reason' => $this->resource->failure_reason,
            'ip_address' => $this->resource->ip_address,
            'browser' => $this->resource->browser,
            'platform' => $this->resource->platform,
            'location' => $this->resource->location,
            'at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
