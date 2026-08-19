<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Change a member's role. Owner is not assignable here (transfer-only).
 * Authorised by OrganizationMemberPolicy::updateRole.
 */
final class UpdateMemberRoleRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $member = $this->route('member');

        if (! $member instanceof OrganizationMember) {
            return false;
        }

        if ($this->actor()?->can('updateRole', $member) !== true) {
            return false;
        }

        $role = $this->input('role');

        // An unreadable role falls through to validation, which answers 422 —
        // refusing here would turn a typo into a permission problem.
        if (! is_string($role) || OrganizationRole::tryFrom($role) === null) {
            return true;
        }

        return $this->mayGrant((int) $member->organization_id, OrganizationRole::from($role));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => [
                'required',
                new Enum(OrganizationRole::class),
                Rule::notIn([OrganizationRole::Owner->value]),
            ],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function role(): OrganizationRole
    {
        return OrganizationRole::from((string) $this->validated('role'));
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
