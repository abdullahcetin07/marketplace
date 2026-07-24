<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Models\User;
use App\Modules\Identity\Domain\Models\LoginAttempt;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence port for login history.
 *
 * The detection queries moved here from statics on `LoginAttempt`. Under
 * ADR-011 they were arguably lightweight helpers, but they are aggregate
 * queries ACROSS rows rather than facts about one row — which makes them
 * repository work.
 *
 * @see App\Modules\Identity\Infrastructure\Repositories\LoginAttemptRepository
 */
interface LoginAttemptRepositoryContract
{
    /**
     * Write an attempt row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes): ?LoginAttempt;

    /**
     * Consecutive failures against one address in a window.
     *
     * Distinct from the rate limiter, which throttles on a sliding window and
     * forgets. This answers "has someone been grinding at this account".
     */
    public function recentFailuresFor(string $email, string $guard, int $minutes = 60): int;

    /**
     * Distinct IPs that have attempted this address recently. A forgetful user
     * comes from one or two; a botnet does not.
     */
    public function distinctIpsFor(string $email, int $minutes = 60): int;

    /**
     * A user's own login history, newest first.
     *
     * @return Collection<int, LoginAttempt>
     */
    public function forUser(User $user, int $limit = 50): Collection;

    public function prune(int $days): int;
}
