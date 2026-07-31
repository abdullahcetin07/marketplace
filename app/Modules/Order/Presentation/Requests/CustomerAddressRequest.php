<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Shared\Enums\UserType;

/**
 * Create or replace an address (ADR-056).
 *
 * ONE REQUEST FOR BOTH, matching the DTO: an address is a small, complete thing a
 * customer retypes rather than patches, so there is no field whose absence should
 * mean "leave it alone".
 *
 * THE COUNTRY IS AN ISO CODE, validated against the `countries` lookup — internal
 * ids never cross a boundary (non-negotiable #7), and validating against the table
 * means a deactivated country is refused here rather than accepted and then
 * rejected by the action.
 *
 * THE FIELDS ARE DELIBERATELY LOOSE. `city` and `district` are free strings
 * because validating world addresses structurally is a project of its own, and
 * getting it half right rejects real addresses — which is worse than accepting an
 * odd one, since a human reads it off a parcel either way.
 */
final class CustomerAddressRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->actor()?->type === UserType::Customer;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:60'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2', 'exists:countries,iso2'],
            'is_default_shipping' => ['sometimes', 'boolean'],
            'is_default_billing' => ['sometimes', 'boolean'],
        ];
    }

    public function toDto(): CustomerAddressDTO
    {
        return new CustomerAddressDTO(
            label: (string) $this->validated('label'),
            recipientName: (string) $this->validated('recipient_name'),
            phone: (string) $this->validated('phone'),
            line1: (string) $this->validated('line1'),
            city: (string) $this->validated('city'),
            countryCode: (string) $this->validated('country'),
            line2: $this->validated('line2'),
            district: $this->validated('district'),
            postalCode: $this->validated('postal_code'),
            isDefaultShipping: (bool) ($this->validated('is_default_shipping') ?? false),
            isDefaultBilling: (bool) ($this->validated('is_default_billing') ?? false),
        );
    }
}
