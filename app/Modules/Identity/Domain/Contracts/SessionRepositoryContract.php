<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Models\User;
use App\Modules\Identity\Domain\Models\UserSession;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence port for sessions.
 *
 * @see App\Modules\Identity\Infrastructure\Repositories\SessionRepository
 */
interface SessionRepositoryContract
{
    /**
     * A user's live sessions, newest activity first, with devices eager-loaded.
     *
     * @return Collection<int, UserSession>
     */
    public function activeFor(User $user, ?string $guard = null): Collection;

    public function findByUuid(string $uuid): ?UserSession;

    /**
     * The row backing one framework session, for the guard in question.
     *
     * Guard-scoped because a user may hold an admin and a customer session
     * simultaneously; closing one must not touch the other.
     */
    public function findForFrameworkSession(User $user, string $sessionId, string $guard): ?UserSession;

    /**
     * Live sessions other than the one given — the "sign out everywhere else"
     * set.
     *
     * @return Collection<int, UserSession>
     */
    public function activeExcept(User $user, ?string $exceptSessionId): Collection;

    public function create(array $attributes): UserSession;

    /**
     * Delete rows idle beyond the retention window. Query-builder delete: this
     * is housekeeping on potentially millions of rows and needs no per-row
     * events.
     */
    public function pruneStale(int $days): int;
}
