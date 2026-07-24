<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Organization\Domain\DTOs\UpdateOrganizationDTO;
use App\Modules\Organization\Domain\Models\Organization;

/**
 * Edit an organization's profile. Authorised by the OrganizationUpdate
 * capability (or admin) via the policy.
 */
final class UpdateOrganizationRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $org = $this->route('organization');

        return $org instanceof Organization && $this->actor()?->can('update', $org) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['sometimes', 'required', 'string', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function toDto(): UpdateOrganizationDTO
    {
        $present = array_values(array_intersect(['legal_name', 'display_name'], array_keys($this->all())));

        return new UpdateOrganizationDTO(
            legalName: $this->input('legal_name'),
            displayName: $this->input('display_name'),
            present: $present,
        );
    }
}
