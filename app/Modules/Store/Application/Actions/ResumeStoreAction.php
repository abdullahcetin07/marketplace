<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Events\StoreActivated;
use App\Modules\Store\Domain\Exceptions\StoreException;
use App\Modules\Store\Domain\Models\Store;

/**
 * Resumes a paused storefront (Paused → Active). The store is serving again, so
 * it emits StoreActivated — consumers care that it is live, not by which path.
 */
final class ResumeStoreAction extends BaseAction
{
    public function handle(mixed ...$arguments): Store
    {
        /** @var Store $store */
        $store = $arguments[0];

        if ($store->status !== StoreStatus::Paused) {
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
