<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Organization\Domain\DTOs\InviteMemberDTO;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Invite a member. Owner is not an assignable role (ownership is transfer-only).
 * Authorised by the MemberInvite capability.
 */
final class InviteMemberRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $org = $this->route('organization');

        if (! $org instanceof Organization || $this->actor()?->can('inviteMembers', $org) !== true) {
            return false;
        }

        $role = $this->input('role');

        if (! is_string($role) || OrganizationRole::tryFrom($role) === null) {
            return true;
        }

        /*
        | **THE INVITE IS THE SAME DOOR.** Refusing self-promotion while leaving
        | invitations open would only add a step: invite a throwaway address as
        | Finance, accept it, change the IBAN.
        */
        return $this->mayGrant((int) $org->getKey(), OrganizationRole::from($role));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'role' => [
                'required',
                new Enum(OrganizationRole::class),
                Rule::notIn([OrganizationRole::Owner->value]),
            ],
        ];
    }

    public function toDto(?int $organizationId = null): InviteMemberDTO
    {
        return new InviteMemberDTO(
            organizationId: $organizationId,
            email: (string) $this->validated('email'),
            role: OrganizationRole::from((string) $this->validated('role')),
            invitedBy: (int) $this->actor()?->getKey(),
        );
    }

    /**
     * The role the actor is trying to confer must be one they already hold in full.
     *
     * **A SEPARATION OF DUTIES IS ONLY REAL IF IT CANNOT BE VOTED AWAY** (security
     * audit, 2026-08-18). `MemberUpdateRole` is the power to move people between
     * roles, not the power to mint capabilities: a Manager conferring Finance would
     * be handing out `BankAccountUpdate` — the payout IBAN — which it does not have.
     *
     * A 403 rather than a validation error, because this is an authorization
     * refusal: the role exists and is well-formed, the actor simply may not grant it.
     */
    private function mayGrant(int $organizationId, OrganizationRole $role): bool
    {
        $actor = $this->actor();

        if ($actor === null) {
            return false;
        }

        $membership = OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->getKey())
            ->first();

        return $membership !== null && $role->isGrantableBy($membership->role);
    }
}
