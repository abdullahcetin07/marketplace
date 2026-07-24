<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Events\OrganizationRejected;
use App\Modules\Organization\Domain\Models\Organization;

/**
 * An admin rejects an organization's KYC.
 *
 * The reason is required (a rejected company must know why): it is persisted to
 * `rejection_reason`, carried on the event for the owner's notification, and —
 * via `withReasonFor` — recorded on the audit entry.
 */
final class RejectOrganizationAction extends BaseAction
{
    public function handle(mixed ...$arguments): Organization
    {
        /** @var Organization $organization */
        $organization = $arguments[0];
        /** @var User $actor */
        $actor = $arguments[1];
        $reason = $arguments[2] ?? null;

        AuditContext::withReasonFor($reason, function () use ($organization, $actor, $reason): void {
            $organization->forceFill([
                'status' => OrganizationStatus::Rejected,
                'rejected_by' => $actor->getKey(),
                'rejection_reason' => $reason,
                'verified_at' => null,
            ])->save();
        });

        return $organization;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Organization $result */
        $reason = $arguments[2] ?? null;

        OrganizationRejected::dispatch($result->getKey(), $result->uuid, $result->owner_id, $reason);
    }
}
