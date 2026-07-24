<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Events\OrganizationMemberRoleChanged;
use App\Modules\Organization\Domain\Exceptions\OwnershipViolation;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;

/**
 * Change a member's role within an organization.
 *
 * The Owner role is untouchable here (ADR-029): a member cannot be promoted TO
 * Owner (ownership arrives only by transfer), and the current Owner's role
 * cannot be reassigned (they must transfer first). Both attempts are refused as
 * domain violations, not silently ignored.
 *
 * The change is a model diff → the Auditable trait records it with the reason.
 */
final class ChangeMemberRoleAction extends BaseAction
{
    private OrganizationRole $previousRole;

    private string $organizationUuid;

    public function handle(mixed ...$arguments): OrganizationMember
    {
        /** @var OrganizationMember $member */
        $member = $arguments[0];
        /** @var OrganizationRole $newRole */
        $newRole = $arguments[1];
        $reason = $arguments[2] ?? null;

        if ($member->isOwner() || $newRole === OrganizationRole::Owner) {
            // The Owner role is reached and left only through an ownership
            // transfer, never a role edit.
            throw OwnershipViolation::ownerRoleImmutable();
        }

        $this->previousRole = $member->role;
        // Resolve the uuid by query rather than lazy-loading the relation
        // (strict mode makes a lazy load throw).
        $this->organizationUuid = (string) Organization::query()
            ->whereKey($member->organization_id)->value('uuid');

        AuditContext::withReasonFor($reason, function () use ($member, $newRole): void {
            $member->forceFill(['role' => $newRole])->save();
        });

        return $member;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var OrganizationMember $result */
        OrganizationMemberRoleChanged::dispatch(
            $result->organization_id,
            $this->organizationUuid,
            $result->user_id,
            $this->previousRole->value,
            $result->role->value,
        );
    }
}
