<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Modules\Organization\Domain\Events\OrganizationMemberRemoved;
use App\Modules\Organization\Domain\Exceptions\OwnershipViolation;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;

/**
 * Remove a member from an organization.
 *
 * THE OWNER CANNOT BE REMOVED (ADR-029). The only way out of ownership is a
 * transfer; an attempt to delete the Owner's membership is refused. Removal is a
 * soft delete — the Auditable trait records who was removed, and by whom, with
 * the reason.
 */
final class RemoveMemberAction extends BaseAction
{
    private int $organizationId;

    private string $organizationUuid;

    private int $removedUserId;

    public function handle(mixed ...$arguments): void
    {
        /** @var OrganizationMember $member */
        $member = $arguments[0];
        $reason = $arguments[1] ?? null;

        if ($member->isOwner()) {
            throw OwnershipViolation::ownerCannotBeRemoved();
        }

        $this->organizationId = $member->organization_id;
        $this->organizationUuid = (string) Organization::query()
            ->whereKey($member->organization_id)->value('uuid');
        $this->removedUserId = $member->user_id;

        AuditContext::withReasonFor($reason, function () use ($member): void {
            $member->delete();
        });
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        OrganizationMemberRemoved::dispatch(
            $this->organizationId,
            $this->organizationUuid,
            $this->removedUserId,
        );
    }
}
