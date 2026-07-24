<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Organization\Domain\Events\StoreOpeningApproved;
use App\Modules\Organization\Domain\Exceptions\StoreOpeningException;
use App\Modules\Organization\Domain\Enums\StoreOpeningRequestStatus;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;

/**
 * An admin approves a Store Opening Request (ADR-028).
 *
 * THE AUTHORITATIVE LIMIT GATE. The store allowance is re-checked here — the
 * plan or override may have changed since submission, and other requests may
 * have been approved in the meantime — so approval is the moment the limit
 * truly binds (§7.3). Approving consumes a slot: `currentStoreCount` counts
 * approved requests.
 *
 * NO STORE IS CREATED HERE. Approval flips the status and fires
 * `StoreOpeningApproved`; the Store module (future) is what turns that into a
 * storefront. An administrative decision — audited with the reviewer's notes.
 */
final class ApproveStoreOpeningRequestAction extends BaseAction
{
    private string $organizationUuid;

    public function handle(mixed ...$arguments): StoreOpeningRequest
    {
        /** @var StoreOpeningRequest $request */
        $request = $arguments[0];
        $notes = $arguments[1] ?? null;
        /** @var User $admin */
        $admin = $arguments[2];

        if (! $request->isPending()) {
            throw StoreOpeningException::invalidTransition();
        }

        // Eager-load the plan: effectiveStoreLimit() reads it (strict mode makes
        // a lazy load throw).
        $organization = Organization::query()->with('plan')->findOrFail($request->organization_id);

        $remaining = $organization->remainingStoreSlots();
        if ($remaining !== null && $remaining <= 0) {
            throw StoreOpeningException::limitReached($organization->effectiveStoreLimit());
        }

        $this->organizationUuid = $organization->uuid;

        AuditContext::withReasonFor($notes, function () use ($request, $notes, $admin): void {
            $request->forceFill([
                'status' => StoreOpeningRequestStatus::Approved,
                'approved_at' => now(),
                'reviewed_by' => $admin->getKey(),
                'admin_notes' => $notes,
            ])->save();
        });

        return $request;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var StoreOpeningRequest $result */
        StoreOpeningApproved::dispatch(
            $result->organization_id,
            $this->organizationUuid,
            $result->uuid,
            $result->requested_by,
            $result->store_name,
            $result->slug,
            $result->category_id,
        );
    }
}
