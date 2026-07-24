<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Organization\Domain\Enums\OrganizationStatus;
use App\Modules\Organization\Domain\Events\OrganizationSuspended;
use App\Modules\Organization\Domain\Models\Organization;

/**
 * An admin suspends an organization — a policy-breach or dispute reaction.
 *
 * Members cannot act until it is restored (§3.1). Reason recorded on the audit
 * entry and carried on the event for the owner's notification.
 */
final class SuspendOrganizationAction extends BaseAction
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
                'status' => OrganizationStatus::Suspended,
                'suspended_at' => now(),
                'suspended_by' => $actor->getKey(),
            ])->save();
        });

        return $organization;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Organization $result */
        $reason = $arguments[2] ?? null;

        OrganizationSuspended::dispatch($result->getKey(), $result->uuid, $result->owner_id, $reason);
    }
}
