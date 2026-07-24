<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Organization\Domain\Enums\StoreOpeningRequestStatus;
use App\Modules\Organization\Domain\Events\StoreOpeningRequested;
use App\Modules\Organization\Domain\Exceptions\StoreOpeningException;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;

/**
 * Submit a draft request into the admin review queue (ADR-028).
 *
 * FAIL-FAST LIMIT CHECK. The store limit (override → plan → config) is checked
 * here so a seller learns immediately that their allowance is used — but this is
 * advisory. The *binding* gate is at approval, because the limit or plan may
 * change while the request waits (§7.3). The organization must also be
 * operational: a company still pending its own KYC cannot open stores (§3.1).
 */
final class SubmitStoreOpeningRequestAction extends BaseAction
{
    private string $organizationUuid;

    public function handle(mixed ...$arguments): StoreOpeningRequest
    {
        /** @var StoreOpeningRequest $request */
        $request = $arguments[0];

        if ($request->status !== StoreOpeningRequestStatus::Draft) {
            throw StoreOpeningException::invalidTransition();
        }

        // Eager-load the plan: effectiveStoreLimit() reads it, and strict mode
        // makes a lazy load throw.
        $organization = Organization::query()->with('plan')->findOrFail($request->organization_id);

        if (! $organization->status->isOperational()) {
            throw StoreOpeningException::invalidTransition();
        }

        $remaining = $organization->remainingStoreSlots();
        if ($remaining !== null && $remaining <= 0) {
            throw StoreOpeningException::limitReached($organization->effectiveStoreLimit());
        }

        $this->organizationUuid = $organization->uuid;

        $request->forceFill([
            'status' => StoreOpeningRequestStatus::Pending,
            'submitted_at' => now(),
        ])->save();

        return $request;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var StoreOpeningRequest $result */
        StoreOpeningRequested::dispatch(
            $result->organization_id,
            $this->organizationUuid,
            $result->uuid,
            $result->requested_by,
        );
    }
}
