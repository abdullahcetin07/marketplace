<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Events\StorePaused;
use App\Modules\Store\Domain\Exceptions\StoreException;
use App\Modules\Store\Domain\Models\Store;

/**
 * Pauses a live storefront (Active → Paused) — vacation mode. Seller-controlled
 * and self-reversible via ResumeStoreAction.
 */
final class PauseStoreAction extends BaseAction
{
    public function handle(mixed ...$arguments): Store
    {
        /** @var Store $store */
        $store = $arguments[0];

        if ($store->status !== StoreStatus::Active) {
            throw StoreException::invalidTransition($store->status, StoreStatus::Paused->value);
        }

        $store->forceFill([
            'status' => StoreStatus::Paused,
            'paused_at' => now(),
        ])->save();

        return $store;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Store $result */
        StorePaused::dispatch($result->getKey(), $result->uuid, $result->organization_id);
    }
}
