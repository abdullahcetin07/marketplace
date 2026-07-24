<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Events\StoreArchived;
use App\Modules\Store\Domain\Exceptions\StoreException;
use App\Modules\Store\Domain\Models\Store;

/**
 * Retires a storefront (Closed or Suspended → Archived) — a read-only business
 * end-state, distinct from the recoverable removal `deleted_at` represents.
 */
final class ArchiveStoreAction extends BaseAction
{
    private const FROM = [StoreStatus::Closed, StoreStatus::Suspended];

    public function handle(mixed ...$arguments): Store
    {
        /** @var Store $store */
        $store = $arguments[0];

        if (! in_array($store->status, self::FROM, true)) {
            throw StoreException::invalidTransition($store->status, StoreStatus::Archived->value);
        }

        $store->forceFill(['status' => StoreStatus::Archived])->save();

        return $store;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Store $result */
        StoreArchived::dispatch($result->getKey(), $result->uuid, $result->organization_id);
    }
}
