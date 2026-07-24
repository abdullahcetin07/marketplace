<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Events\StoreClosed;
use App\Modules\Store\Domain\Exceptions\StoreException;
use App\Modules\Store\Domain\Models\Store;

/**
 * Closes a storefront by seller choice (Active or Paused → Closed). Reversible
 * by reopening (ActivateStoreAction). Distinct from an admin suspension.
 */
final class CloseStoreAction extends BaseAction
{
    private const FROM = [StoreStatus::Active, StoreStatus::Paused];

    public function handle(mixed ...$arguments): Store
    {
        /** @var Store $store */
        $store = $arguments[0];

        if (! in_array($store->status, self::FROM, true)) {
            throw StoreException::invalidTransition($store->status, StoreStatus::Closed->value);
        }

        $store->forceFill([
            'status' => StoreStatus::Closed,
            'closed_at' => now(),
        ])->save();

        return $store;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Store $result */
        StoreClosed::dispatch($result->getKey(), $result->uuid, $result->organization_id);
    }
}
