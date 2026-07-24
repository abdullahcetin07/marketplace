<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Transfer ownership to another member. Authorised by the OwnershipTransfer
 * capability (only the Owner holds it).
 */
final class TransferOwnershipRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $org = $this->route('organization');

        return $org instanceof Organization && $this->actor()?->can('transferOwnership', $org) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member' => ['required', 'string', Rule::exists('organization_members', 'uuid')],
            'demoted_role' => ['sometimes', new Enum(OrganizationRole::class), Rule::notIn([OrganizationRole::Owner->value])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function demotedRole(): OrganizationRole
    {
        $value = $this->validated('demoted_role');

        return $value === null ? OrganizationRole::Manager : OrganizationRole::from((string) $value);
    }
}
