<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentType;
use App\Modules\Organization\Domain\Models\Organization;
use Illuminate\Validation\Rules\Enum;

/**
 * Upload a KYC document (PDF or image, private disk). Authorised by the
 * DocumentUpload capability via ManageKyc-adjacent membership; here we require
 * the actor to be an active member who can manage KYC.
 */
final class UploadDocumentRequest extends BaseRequest
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
            'type' => ['required', new Enum(OrganizationDocumentType::class)],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    public function documentType(): OrganizationDocumentType
    {
        return OrganizationDocumentType::from((string) $this->validated('type'));
    }
}
