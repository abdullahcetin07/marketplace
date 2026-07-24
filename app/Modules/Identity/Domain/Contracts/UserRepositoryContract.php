<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Models\User;
use App\Shared\Enums\UserType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence port for user lookup.
 *
 * WHY THIS EXISTS: `LoginAction` currently builds its own query from
 * `UserType::model()`. That query encodes the single most important security
 * property in the platform — resolution through the TYPE-SCOPED model, so a
 * seller posting to the admin login is indistinguishable from a non-existent
 * account. It belongs in one place, not repeated at every call site.
 *
 * @see App\Modules\Identity\Infrastructure\Repositories\UserRepository
 */
interface UserRepositoryContract
{
    /**
     * Resolve by email WITHIN one actor type.
     *
     * Goes through the type-scoped subclass, never the base `User`. That global
     * scope is what makes the three guards genuinely independent.
     */
    public function findByEmailForType(string $email, UserType $type): ?User;

    /**
     * Resolve by public identifier. Never by internal id — that never leaves
     * the application.
     */
    public function findByUuid(string $uuid): ?User;

    public function findOrFailByUuid(string $uuid): User;

    /**
     * Whether an address is already taken within an actor type.
     *
     * Scoped, because uniqueness is `(type, email)` — the same address may
     * legitimately exist as both a seller and a customer (ADR-012).
     */
    public function emailExistsForType(string $email, UserType $type): bool;

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginateOfType(UserType $type, int $perPage = 25): LengthAwarePaginator;

    /**
     * The admin listing — across all actor types, optionally filtered to one.
     *
     * Queries the base `User` (no type scope) on purpose: guard isolation is an
     * authentication property, and an admin holding `user.view_any` manages
     * every type. An optional `$type` narrows it; `$search` matches name/email.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(?UserType $type = null, ?string $search = null, int $perPage = 25): LengthAwarePaginator;

    /**
     * Active administrators who should receive platform security alerts (Q6).
     *
     * Permission-gated on `security.receive_alerts`, not "every admin" — the
     * first-level authorization check that scales from five admins to hundreds.
     * The Notification module's per-user preferences will sit BEHIND this gate
     * (permission → preference → send), never replace it. @see docs/notifications.md
     *
     * @return Collection<int, User>
     */
    public function securityAlertRecipients(): Collection;
}
