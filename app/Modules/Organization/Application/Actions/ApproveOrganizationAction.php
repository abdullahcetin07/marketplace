<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Events\OrganizationApproved;
use App\Modules\Organization\Domain\Models\Organization;

/**
 * An admin approves an organization's KYC — it becomes operational.
 *
 * The status flip is a model diff, so the Auditable trait records it; wrapping
 * the write in `withReasonFor` puts the admin's reason on that entry. The
 * announce (after commit) drives the owner notification and the timeline.
 */
final class ApproveOrganizationAction extends BaseAction
{
    public function handle(mixed ...$arguments): Organization
    {
        /** @var Organization $organization */
        $organization = $arguments[0];
        /** @var User $actor */
        $actor = $arguments[1];
        $reason = $arguments[2] ?? null;

        AuditContext::withReasonFor($reason, function () use ($organization, $actor): void {
            $organization->forceFill([
                'status' => OrganizationStatus::Approved,
                'verified_at' => now(),
                'approved_by' => $actor->getKey(),
                'rejection_reason' => null,
            ])->save();
        });

        return $organization;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Organization $result */
        OrganizationApproved::dispatch($result->getKey(), $result->uuid, $result->owner_id);
    }
}
