<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Events\StoreReinstated;
use App\Modules\Store\Domain\Exceptions\StoreException;
use App\Modules\Store\Domain\Models\Store;

/**
 * An admin lifts a suspension (Suspended → its exact prior state).
 *
 * Restores `status_before_suspension` rather than guessing Active — a store
 * suspended while Draft returns to Draft, not live. Clears the suspension fields
 * and records the actor (ADR-027).
 */
final class ReinstateStoreAction extends BaseAction
{
    public function handle(mixed ...$arguments): Store
    {
        /** @var Store $store */
        $store = $arguments[0];
        /** @var User $admin */
        $admin = $arguments[1];
        $reason = $arguments[2] ?? null;

        if ($store->status !== StoreStatus::Suspended) {
            throw StoreException::invalidTransition($store->status, 'reinstated');
        }

        // Fall back to Draft only if the prior state was somehow not recorded.
        $restored = $store->status_before_suspension ?? StoreStatus::Draft;

        AuditContext::withReasonFor($reason, function () use ($store, $restored): void {
            $store->forceFill([
                'status' => $restored,
                'status_before_suspension' => null,
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
            ])->save();
        });

        return $store;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Store $result */
        /** @var User $admin */
        $admin = $arguments[1];

        StoreReinstated::dispatch($result->getKey(), $result->uuid, $result->organization_id, $admin->getKey());
    }
}
