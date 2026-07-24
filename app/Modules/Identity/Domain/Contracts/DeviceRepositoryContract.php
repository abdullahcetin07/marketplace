<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Models\User;
use App\Modules\Identity\Domain\Models\UserDevice;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence port for devices.
 *
 * @see App\Modules\Identity\Infrastructure\Repositories\DeviceRepository
 */
interface DeviceRepositoryContract
{
    public function findByFingerprint(User $user, string $fingerprint): ?UserDevice;

    public function findByUuid(string $uuid): ?UserDevice;

    /**
     * Whether this user has been seen on this fingerprint before.
     *
     * Must be checked BEFORE a session is created, or every login looks like a
     * new device and the "new sign-in" notification fires every time.
     */
    public function existsForFingerprint(User $user, string $fingerprint): bool;

    /**
     * @return Collection<int, UserDevice>
     */
    public function forUser(User $user): Collection;

    /**
     * Find or create by (user, fingerprint), stamping last use.
     *
     * One row per browser per user — re-signing in updates, never duplicates,
     * or the security page becomes fifty identical rows of noise.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function resolve(User $user, string $fingerprint, array $attributes): UserDevice;
}
