<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Events\StoreSuspended;
use App\Modules\Store\Domain\Exceptions\StoreException;
use App\Modules\Store\Domain\Models\Store;

/**
 * An admin freezes a storefront for a policy breach (any live state → Suspended).
 *
 * Records the pre-suspension state so ReinstateStoreAction restores it exactly,
 * plus the actor and reason for the forensic trail (ADR-027). An administrative
 * decision — the reason is threaded into audit metadata like the Organization
 * admin actions.
 */
final class SuspendStoreAction extends BaseAction
{
    public function handle(mixed ...$arguments): Store
    {
        /** @var Store $store */
        $store = $arguments[0];
        /** @var User $admin */
        $admin = $arguments[1];
        $reason = $arguments[2] ?? null;

        if (! $store->status->isSellerMutable()) {
            // Already suspended, or archived (terminal).
            throw StoreException::invalidTransition($store->status, StoreStatus::Suspended->value);
        }

        AuditContext::withReasonFor($reason, function () use ($store, $admin, $reason): void {
            $store->forceFill([
                'status_before_suspension' => $store->status,
                'status' => StoreStatus::Suspended,
                'suspended_at' => now(),
                'suspended_by' => $admin->getKey(),
                'suspension_reason' => $reason,
            ])->save();
        });

        return $store;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Store $result */
        StoreSuspended::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->organization_id,
            $result->suspended_by,
            $result->suspension_reason,
        );
    }
}
