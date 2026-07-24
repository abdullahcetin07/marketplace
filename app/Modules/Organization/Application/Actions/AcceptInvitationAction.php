<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\InvitationTokenizerContract;
use App\Models\User;
use App\Modules\Organization\Domain\Contracts\OrganizationInvitationRepositoryContract;
use App\Modules\Organization\Domain\Contracts\OrganizationMemberRepositoryContract;
use App\Modules\Organization\Domain\Enums\OrganizationMemberStatus;
use App\Modules\Organization\Domain\Events\OrganizationMemberJoined;
use App\Modules\Organization\Domain\Exceptions\InvitationException;
use App\Modules\Organization\Domain\Models\OrganizationMember;

/**
 * Accept an invitation and become a member (ADR-031).
 *
 * THE INVARIANTS THIS ENFORCES:
 *
 *  - **An authenticated account is required.** The action is handed a `User`
 *    resolved from the auth guard; it never creates one. A recipient with no
 *    account registered first (the front end routes them there and back).
 *  - **Only the intended recipient may accept.** The authenticated account's
 *    email must match the invited address — holding the link is not enough.
 *  - **Single-use.** The token is looked up by its hash; a used, cancelled or
 *    expired invitation is not acceptable, and acceptance marks it so.
 *
 * Member creation and marking the invitation accepted share one transaction.
 */
final class AcceptInvitationAction extends BaseAction
{
    private int $organizationId;

    private string $organizationUuid;

    private int $userId;

    private string $role;

    public function __construct(
        private readonly InvitationTokenizerContract $tokenizer,
        private readonly OrganizationInvitationRepositoryContract $invitations,
        private readonly OrganizationMemberRepositoryContract $members,
    ) {}

    public function handle(mixed ...$arguments): OrganizationMember
    {
        $rawToken = (string) $arguments[0];
        /** @var User $user */
        $user = $arguments[1];

        $invitation = $this->invitations->findByTokenHash($this->tokenizer->hash($rawToken));

        if ($invitation === null || ! $invitation->isAcceptable()) {
            throw InvitationException::notAcceptable();
        }

        if (mb_strtolower(trim($user->email)) !== $invitation->email) {
            throw InvitationException::emailMismatch();
        }

        if ($this->members->findMembership($invitation->organization_id, $user->getKey()) !== null) {
            throw InvitationException::alreadyMember();
        }

        $member = OrganizationMember::query()->create([
            'organization_id' => $invitation->organization_id,
            'user_id' => $user->getKey(),
            'role' => $invitation->role,
            'status' => OrganizationMemberStatus::Active,
            'invited_by' => $invitation->invited_by,
            'joined_at' => now(),
        ]);

        $invitation->markAccepted($user->getKey());

        $this->organizationId = $invitation->organization_id;
        $this->organizationUuid = (string) $invitation->organization->uuid;
        $this->userId = $user->getKey();
        $this->role = $invitation->role->value;

        return $member;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        OrganizationMemberJoined::dispatch(
            $this->organizationId,
            $this->organizationUuid,
            $this->userId,
            $this->role,
        );
    }
}
