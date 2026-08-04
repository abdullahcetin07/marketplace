<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Models\User;
use App\Modules\Identity\Domain\Contracts\LoginAttemptRepositoryContract;
use App\Modules\Identity\Domain\Models\LoginAttempt;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Login history persistence and attack-detection queries.
 *
 * @see App\Modules\Identity\Domain\Contracts\LoginAttemptRepositoryContract
 * @see docs/audit.md
 */
final class LoginAttemptRepository implements LoginAttemptRepositoryContract
{
    /**
     * Write an attempt row.
     *
     * NEVER ALLOWED TO BREAK A LOGIN. A logging failure must not turn a valid
     * sign-in into a 500, so this swallows and reports rather than throwing.
     * Returns null when the write failed.
     *
     * @param array<string, mixed> $attributes
     */
    public function record(array $attributes): ?LoginAttempt
    {
        try {
            return LoginAttempt::query()->create($attributes);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function recentFailuresFor(string $email, string $guard, int $minutes = 60): int
    {
        return LoginAttempt::query()
            ->where('email', mb_strtolower($email))
            ->where('guard', $guard)
            ->where('successful', false)
            ->where('created_at', '>', now()->subMinutes($minutes))
            ->count();
    }

    public function distinctIpsFor(string $email, int $minutes = 60): int
    {
        return LoginAttempt::query()
            ->where('email', mb_strtolower($email))
            ->where('created_at', '>', now()->subMinutes($minutes))
            ->distinct()
            ->count('ip_address');
    }

    /**
     * @return Collection<int, LoginAttempt>
     */
    public function forUser(User $user, int $limit = 50): Collection
    {
        return LoginAttempt::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('id')
            ->limit(min($limit, 200))
            ->get();
    }

    public function prune(int $days): int
    {
        // Query-builder delete: the model is append-only and refuses deletes,
        // and per-row events on millions of rows would be pointless.
        return LoginAttempt::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
