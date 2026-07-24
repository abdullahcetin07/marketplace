<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Requests\Admin;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentStatus;
use App\Modules\Organization\Domain\Models\OrganizationDocument;
use Illuminate\Validation\Rule;

/**
 * An admin's document review — approve, ask for a revision, or reject.
 * Authorised by OrganizationDocumentPolicy::review.
 */
final class ReviewDocumentRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof OrganizationDocument
            && $this->actor()?->can('review', $document) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in([
                OrganizationDocumentStatus::Approved->value,
                OrganizationDocumentStatus::NeedsRevision->value,
                OrganizationDocumentStatus::Rejected->value,
            ])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function decision(): OrganizationDocumentStatus
    {
        return OrganizationDocumentStatus::from((string) $this->validated('decision'));
    }
}
