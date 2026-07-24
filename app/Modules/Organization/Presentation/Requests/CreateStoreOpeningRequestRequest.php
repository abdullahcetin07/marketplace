<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Organization\Domain\DTOs\CreateStoreOpeningRequestDTO;
use App\Modules\Organization\Domain\Models\Organization;

/**
 * Create a Store Opening Request. Authorised by the StoreRequestCreate
 * capability. No store is created — this drafts a request (ADR-028).
 */
final class CreateStoreOpeningRequestRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $org = $this->route('organization');

        return $org instanceof Organization && $this->actor()?->can('createStoreRequest', $org) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function toDto(?int $organizationId = null): CreateStoreOpeningRequestDTO
    {
        return new CreateStoreOpeningRequestDTO(
            organizationId: $organizationId,
            requestedBy: (int) $this->actor()?->getKey(),
            storeName: (string) $this->validated('store_name'),
            slug: (string) $this->validated('slug'),
            categoryId: $this->validated('category_id') !== null ? (int) $this->validated('category_id') : null,
            description: $this->validated('description'),
            reason: $this->validated('reason'),
        );
    }
}
