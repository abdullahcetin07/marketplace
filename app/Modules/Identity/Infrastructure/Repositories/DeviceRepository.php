<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Models\User;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryContract;
use App\Modules\Identity\Domain\Models\UserDevice;
use Illuminate\Database\Eloquent\Collection;

/**
 * Device persistence.
 *
 * @see App\Modules\Identity\Domain\Contracts\DeviceRepositoryContract
 */
final class DeviceRepository implements DeviceRepositoryContract
{
    public function findByFingerprint(User $user, string $fingerprint): ?UserDevice
    {
        return UserDevice::query()
            ->where('user_id', $user->getKey())
            ->where('fingerprint', $fingerprint)
            ->first();
    }

    public function findByUuid(string $uuid): ?UserDevice
    {
        return UserDevice::query()->where('uuid', $uuid)->first();
    }

    public function existsForFingerprint(User $user, string $fingerprint): bool
    {
        return UserDevice::query()
            ->where('user_id', $user->getKey())
            ->where('fingerprint', $fingerprint)
            ->exists();
    }

    /**
     * @return Collection<int, UserDevice>
     */
    public function forUser(User $user): Collection
    {
        return UserDevice::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_used_at')
            ->get();
    }

    /**
     * Find or create by (user, fingerprint), stamping last use.
     *
     * `firstOrNew` then `fill`, not `updateOrCreate`: the caller supplies only
     * the fields it can observe, and overwriting a user-assigned device `name`
     * with null on every sign-in would be a regression.
     *
     * @param array<string, mixed> $attributes
     */
    public function resolve(User $user, string $fingerprint, array $attributes): UserDevice
    {
        $device = UserDevice::query()->firstOrNew([
            'user_id' => $user->getKey(),
            'fingerprint' => $fingerprint,
        ]);

        $device->fill($attributes)->save();

        return $device;
    }
}
