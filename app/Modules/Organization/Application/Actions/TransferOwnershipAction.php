<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Models\User;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Events\OrganizationOwnerTransferred;
use App\Modules\Organization\Domain\Exceptions\OwnershipViolation;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Shared\Enums\UserType;

/**
 * Transfer ownership of an organization to another member (ADR-029).
 *
 * ATOMIC. In one transaction it demotes the current Owner to a chosen role,
 * promotes the target member to Owner, and repoints `organizations.owner_id`.
 * There is never an instant with zero or two Owners — the invariant holds
 * across the whole operation, not just at its ends.
 *
 * The target must be an ACTIVE member of THIS organization and a seller-guard
 * user. NOTE: "a Seller Employee cannot own" (ADR-029) is a *role* distinction,
 * not a type one — both are `type = seller`. The guard here enforces the
 * type-level gate; the finer Seller-role-vs-Seller-Employee-role eligibility is
 * wired when seller-role assignment lands (Phase 6). All three writes are model
 * diffs, so the Auditable trail records the demotion, the promotion and the
 * owner repoint, each with the reason.
 */
final class TransferOwnershipAction extends BaseAction
{
    public function __construct(
        private readonly OrganizationMemberRepositoryContract $members,
    ) {}

    private int $previousOwnerId;

    private int $newOwnerId;

    public function handle(mixed ...$arguments): Organization
    {
        /** @var Organization $organization */
        $organization = $arguments[0];
        /** @var OrganizationMember $target */
        $target = $arguments[1];
        $reason = $arguments[2] ?? null;
        /** @var OrganizationRole $demotedRole */
        $demotedRole = $arguments[3] ?? OrganizationRole::Manager;

        $this->guardTarget($organization, $target);

        $currentOwner = $this->members->ownerMember($organization->getKey());

        $this->previousOwnerId = $organization->owner_id;
        $this->newOwnerId = $target->user_id;

        AuditContext::withReasonFor($reason, function () use ($organization, $target, $currentOwner, $demotedRole): void {
            // Demote the outgoing owner to the chosen role. Null-safe: the
            // owner membership always exists (ADR-029), but a data-repair path
            // must not fatal.
            $currentOwner?->forceFill(['role' => $demotedRole])->save();

            $target->forceFill(['role' => OrganizationRole::Owner])->save();

            $organization->forceFill(['owner_id' => $target->user_id])->save();
        });

        return $organization->refresh();
    }

    /**
     * @throws OwnershipViolation
     */
    private function guardTarget(Organization $organization, OrganizationMember $target): void
    {
        if ($target->organization_id !== $organization->getKey() || ! $target->isActive()) {
            throw OwnershipViolation::transferTargetInactive();
        }

        $user = User::query()->find($target->user_id);

        if ($user === null || $user->type !== UserType::Seller) {
            throw OwnershipViolation::transferTargetCannotOwn();
        }
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Organization $result */
        OrganizationOwnerTransferred::dispatch(
            $result->getKey(),
            $result->uuid,
            $this->previousOwnerId,
            $this->newOwnerId,
        );
    }
}
