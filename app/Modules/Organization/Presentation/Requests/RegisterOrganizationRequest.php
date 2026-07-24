<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Organization\Domain\DTOs\RegisterOrganizationDTO;
use App\Shared\Enums\UserType;
use Illuminate\Validation\Rule;

/**
 * Register a new organization. Any authenticated seller may create one (ADR-030
 * allows a user to belong to/own several); the owner is the acting seller.
 */
final class RegisterOrganizationRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();

        return $actor !== null && $actor->type === UserType::Seller;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('organizations', 'slug')],
            'country_code' => ['required', 'string', 'size:2', Rule::exists('countries', 'iso2')->where('is_active', true)],
            'currency_code' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
            'plan_slug' => ['sometimes', 'nullable', 'string', Rule::exists('organization_plans', 'slug')->where('is_active', true)],
        ];
    }

    public function toDto(): RegisterOrganizationDTO
    {
        return new RegisterOrganizationDTO(
            ownerId: (int) $this->actor()?->getKey(),
            legalName: (string) $this->validated('legal_name'),
            displayName: $this->validated('display_name'),
            slug: (string) $this->validated('slug'),
            countryCode: (string) $this->validated('country_code'),
            currencyCode: (string) $this->validated('currency_code'),
            planSlug: $this->validated('plan_slug'),
        );
    }
}
