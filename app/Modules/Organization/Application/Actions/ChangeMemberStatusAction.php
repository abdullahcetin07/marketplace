<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Modules\Organization\Domain\Enums\OrganizationMemberStatus;
use App\Modules\Organization\Domain\Events\OrganizationMemberStatusChanged;
use App\Modules\Organization\Domain\Exceptions\OwnershipViolation;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;

/**
 * Freezes or thaws a membership without ending it (§2.2).
 *
 * THE MIDDLE GROUND THE MODULE WAS MISSING. Until now a seller's only lever was
 * removal — a soft delete that costs the person their role and forces a fresh
 * invitation to undo. An employee on leave, a contractor between engagements or
 * an account under internal review all needed something reversible, and the
 * `status` column was already there to carry it.
 *
 * THE OWNER CANNOT BE DEACTIVATED (ADR-029). Exactly one Owner exists per
 * organization and they can never be removed; deactivating them would achieve
 * the same thing through a different column — an org whose only Owner cannot
 * act, with no one able to transfer ownership away. Refused for the same reason
 * `RemoveMemberAction` refuses.
 *
 * The change is a model diff, so the Auditable trait records it with the reason;
 * the event carries both sides so a listener need not query for what moved.
 *
 * @see docs/modules/Organization.md §2.2
 */
final class ChangeMemberStatusAction extends BaseAction
{
    private OrganizationMemberStatus $previousStatus;

    private string $organizationUuid;

    public function handle(mixed ...$arguments): OrganizationMember
    {
        /** @var OrganizationMember $member */
        $member = $arguments[0];
        /** @var OrganizationMemberStatus $status */
        $status = $arguments[1];
        $reason = $arguments[2] ?? null;

        if ($member->isOwner()) {
            // Freezing the Owner is removing them by another name.
            throw OwnershipViolation::ownerCannotBeRemoved();
        }

        $this->previousStatus = $member->status;
        // Resolved by query rather than lazy-loading the relation (strict mode
        // makes a lazy load throw) — the same shape as ChangeMemberRoleAction.
        $this->organizationUuid = (string) Organization::query()
            ->whereKey($member->organization_id)->value('uuid');

        AuditContext::withReasonFor($reason, function () use ($member, $status): void {
            $member->forceFill(['status' => $status])->save();
        });

        return $member;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var OrganizationMember $result */
        OrganizationMemberStatusChanged::dispatch(
            $result->organization_id,
            $this->organizationUuid,
            $result->user_id,
            $this->previousStatus->value,
            $result->status->value,
        );
    }
}
