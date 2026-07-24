<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Models\User;
use App\Modules\Identity\Domain\Contracts\UserRepositoryContract;
use App\Shared\Enums\Status;
use App\Shared\Enums\UserType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * User lookup.
 *
 * THE SECURITY-CRITICAL DETAIL: every method resolves through the TYPE-SCOPED
 * subclass returned by `UserType::model()`, never through the base `User`.
 * `Admin`, `Seller` and `Customer` each carry a global scope on `users.type`,
 * so the admin provider's query cannot see a seller's row. That is the whole
 * guard-isolation mechanism, and centralising it here means no call site can
 * accidentally bypass it by querying `User` directly.
 *
 * @see App\Modules\Identity\Domain\Contracts\UserRepositoryContract
 * @see docs/authentication.md
 */
final class UserRepository implements UserRepositoryContract
{
    /**
     * Eager loads applied to every user read.
     *
     * Strict mode makes lazy loading THROW, and `UserResource` reads all four
     * locale relations. Declared once here rather than rediscovered by whoever
     * hits the exception next.
     *
     * @var array<int, string>
     */
    private const array WITH = ['language', 'country', 'currency', 'timezone'];

    public function findByEmailForType(string $email, UserType $type): ?User
    {
        /** @var class-string<User> $model */
        $model = $type->model();

        return $model::query()
            ->with(self::WITH)
            ->where('email', mb_strtolower(trim($email)))
            ->first();
    }

    public function findByUuid(string $uuid): ?User
    {
        return User::query()->with(self::WITH)->where('uuid', $uuid)->first();
    }

    public function findOrFailByUuid(string $uuid): User
    {
        $user = $this->findByUuid($uuid);

        if ($user === null) {
            throw (new ModelNotFoundException)->setModel(User::class, [$uuid]);
        }

        return $user;
    }

    public function emailExistsForType(string $email, UserType $type): bool
    {
        /** @var class-string<User> $model */
        $model = $type->model();

        // No eager loads: existence needs no relations.
        return $model::query()->where('email', mb_strtolower(trim($email)))->exists();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginateOfType(UserType $type, int $perPage = 25): LengthAwarePaginator
    {
        /** @var class-string<User> $model */
        $model = $type->model();

        return $model::query()
            ->with(self::WITH)
            ->orderByDesc('id')
            // Clamped: `?per_page=100000` is a free denial-of-service.
            ->paginate(min($perPage, (int) config('marketplace.pagination.max_per_page', 100)));
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(?UserType $type = null, ?string $search = null, int $perPage = 25): LengthAwarePaginator
    {
        return User::query()
            ->with(self::WITH)
            ->when($type !== null, fn ($q) => $q->where('type', $type->value))
            ->when(
                $search !== null && $search !== '',
                fn ($q) => $q->where(function ($q) use ($search): void {
                    $like = '%'.mb_strtolower($search).'%';
                    $q->whereRaw('lower(email) like ?', [$like])
                        ->orWhereRaw('lower(first_name) like ?', [$like])
                        ->orWhereRaw('lower(last_name) like ?', [$like]);
                }),
            )
            ->orderByDesc('id')
            ->paginate(min($perPage, (int) config('marketplace.pagination.max_per_page', 100)));
    }

    /**
     * @return Collection<int, User>
     */
    public function securityAlertRecipients(): Collection
    {
        /** @var class-string<User> $model */
        $model = UserType::Admin->model();

        // Permission-gated, not "every admin" (Q6): only holders of
        // `security.receive_alerts`, directly or through a role. This is the
        // first-level authorization check that scales from five admins to
        // hundreds — the Notification module's per-user preferences will sit
        // BEHIND it, never replace it. Suspended and soft-deleted accounts are
        // excluded; no eager loads, the mail greeting reads first_name only.
        return $model::query()
            ->permission('security.receive_alerts')
            ->where('status', Status::Active)
            ->get();
    }
}
