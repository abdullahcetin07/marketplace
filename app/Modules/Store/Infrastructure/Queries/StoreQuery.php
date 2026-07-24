<?php

declare(strict_types=1);

namespace App\Modules\Store\Infrastructure\Queries;

use App\Core\Domain\Contracts\StoreQueryContract;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/**
 * Store's implementation of the downstream read port (ADR-033).
 *
 * Returns only the minimum a foreign module needs — existence, liveness, owning
 * org — never a model, so callers cannot reach into Store's internals through
 * it. `isLive` mirrors the public-visibility rule so a downstream module and the
 * public surface agree on what "live" means.
 *
 * @see App\Core\Domain\Contracts\StoreQueryContract
 */
final class StoreQuery implements StoreQueryContract
{
    public function exists(string $storeUuid): bool
    {
        return Store::query()->where('uuid', $storeUuid)->exists();
    }

    public function isLive(string $storeUuid): bool
    {
        return Store::query()
            ->where('uuid', $storeUuid)
            ->where('status', StoreStatus::Active->value)
            ->exists();
    }

    public function organizationIdFor(string $storeUuid): ?int
    {
        $id = Store::query()->where('uuid', $storeUuid)->value('organization_id');

        return $id === null ? null : (int) $id;
    }
}
