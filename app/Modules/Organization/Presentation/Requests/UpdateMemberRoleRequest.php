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

        return $member instanceof OrganizationMember
            && $this->actor()?->can('updateRole', $member) === true;
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
}
