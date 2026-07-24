<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Events\StoreActivated;
use App\Modules\Store\Domain\Exceptions\StoreException;
use App\Modules\Store\Domain\Models\Store;

/**
 * Takes a storefront live (Draft or Closed → Active).
 *
 * Requires only a valid from-state and a set locale (creation always provides
 * one). Stores are path-addressed `/store/{slug}` with no per-store domain
 * (ADR-035). Emits StoreActivated after commit so consumers react to a durable
 * fact.
 */
final class ActivateStoreAction extends BaseAction
{
    private const FROM = [StoreStatus::Draft, StoreStatus::Closed];

    public function handle(mixed ...$arguments): Store
    {
        /** @var Store $store */
        $store = $arguments[0];

        if (! in_array($store->status, self::FROM, true)) {
            throw StoreException::invalidTransition($store->status, StoreStatus::Active->value);
        }

        $store->forceFill([
            'status' => StoreStatus::Active,
            'activated_at' => now(),
        ])->save();

        return $store;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Store $result */
        StoreActivated::dispatch($result->getKey(), $result->uuid, $result->organization_id);
    }
}
