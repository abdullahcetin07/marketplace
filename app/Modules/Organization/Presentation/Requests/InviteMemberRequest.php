<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Organization\Domain\DTOs\InviteMemberDTO;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
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

        return $org instanceof Organization && $this->actor()?->can('inviteMembers', $org) === true;
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
}
