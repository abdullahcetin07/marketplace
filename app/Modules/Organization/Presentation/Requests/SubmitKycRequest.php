<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Organization\Domain\DTOs\SubmitKycDTO;
use App\Modules\Organization\Domain\Models\Organization;

/**
 * Submit KYC. Country-agnostic core fields plus a free `metadata` bag for
 * jurisdiction-specific identifiers. Authorised by the ManageKyc capability.
 */
final class SubmitKycRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $org = $this->route('organization');

        return $org instanceof Organization && $this->actor()?->can('manageKyc', $org) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'authorized_person_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'authorized_person_national_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function toDto(?int $organizationId = null): SubmitKycDTO
    {
        return new SubmitKycDTO(
            organizationId: $organizationId,
            taxNumber: $this->input('tax_number'),
            registrationNumber: $this->input('registration_number'),
            authorizedPersonName: $this->input('authorized_person_name'),
            authorizedPersonNationalId: $this->input('authorized_person_national_id'),
            metadata: (array) ($this->validated('metadata') ?? []),
        );
    }
}
