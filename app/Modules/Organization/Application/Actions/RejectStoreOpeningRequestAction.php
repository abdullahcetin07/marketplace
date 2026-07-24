<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Organization\Domain\Enums\StoreOpeningRequestStatus;
use App\Modules\Organization\Domain\Events\StoreOpeningRejected;
use App\Modules\Organization\Domain\Exceptions\StoreOpeningException;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;

/**
 * An admin rejects a Store Opening Request (ADR-028).
 *
 * No store, no slot consumed. Audited with the reviewer's notes; the seller is
 * notified with them. Only a pending request can be decided.
 */
final class RejectStoreOpeningRequestAction extends BaseAction
{
    private string $organizationUuid;

    private ?string $notes;

    public function handle(mixed ...$arguments): StoreOpeningRequest
    {
        /** @var StoreOpeningRequest $request */
        $request = $arguments[0];
        $this->notes = $arguments[1] ?? null;
        /** @var User $admin */
        $admin = $arguments[2];

        if (! $request->isPending()) {
            throw StoreOpeningException::invalidTransition();
        }

        $this->organizationUuid = (string) Organization::query()
            ->whereKey($request->organization_id)->value('uuid');

        AuditContext::withReasonFor($this->notes, function () use ($request, $admin): void {
            $request->forceFill([
                'status' => StoreOpeningRequestStatus::Rejected,
                'rejected_at' => now(),
                'reviewed_by' => $admin->getKey(),
                'admin_notes' => $this->notes,
            ])->save();
        });

        return $request;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var StoreOpeningRequest $result */
        StoreOpeningRejected::dispatch(
            $result->organization_id,
            $this->organizationUuid,
            $result->uuid,
            $result->requested_by,
            $this->notes,
        );
    }
}
