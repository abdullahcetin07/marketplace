<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Models\User;
use App\Modules\Identity\Domain\Contracts\SessionRepositoryContract;
use App\Modules\Identity\Domain\Models\UserSession;
use Illuminate\Database\Eloquent\Collection;

/**
 * Session persistence.
 *
 * @see App\Modules\Identity\Domain\Contracts\SessionRepositoryContract
 */
final class SessionRepository implements SessionRepositoryContract
{
    /**
     * The security page renders a device label per row. Without this eager
     * load, strict mode throws on the first row.
     *
     * @var array<int, string>
     */
    private const array WITH = ['device'];

    /**
     * @return Collection<int, UserSession>
     */
    public function activeFor(User $user, ?string $guard = null): Collection
    {
        return UserSession::query()
            ->where('user_id', $user->getKey())
            ->when($guard !== null, fn ($q) => $q->where('guard', $guard))
            ->active()
            ->with(self::WITH)
            ->orderByDesc('last_activity_at')
            ->get();
    }

    public function findByUuid(string $uuid): ?UserSession
    {
        return UserSession::query()->with(self::WITH)->where('uuid', $uuid)->first();
    }

    public function findForFrameworkSession(User $user, string $sessionId, string $guard): ?UserSession
    {
        return UserSession::query()
            ->where('user_id', $user->getKey())
            ->where('guard', $guard)
            ->where('session_id', $sessionId)
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * @return Collection<int, UserSession>
     */
    public function activeExcept(User $user, ?string $exceptSessionId): Collection
    {
        return UserSession::query()
            ->where('user_id', $user->getKey())
            ->active()
            ->when($exceptSessionId !== null, fn ($q) => $q->where('session_id', '!=', $exceptSessionId))
            ->get();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): UserSession
    {
        return UserSession::query()->create($attributes);
    }

    public function pruneStale(int $days): int
    {
        return UserSession::query()->stale($days)->delete();
    }
}
