<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Identity\Domain\DTOs\RegisterUserDTO;
use App\Shared\Enums\UserType;
use App\Shared\Rules\StrongPassword;
use Illuminate\Validation\Rule;

/**
 * Registration input.
 *
 * Locale preferences are accepted as ISO CODES and validated against the
 * lookup tables with `exists`, scoped to ACTIVE rows — a client must not be
 * able to select a currency the operator has disabled by knowing its code.
 */
final class RegisterRequest extends BaseRequest
{
    protected ?string $dto = RegisterUserDTO::class;

    public function authorize(): bool
    {
        // Seller self-registration is switchable by the operator; customer
        // registration is always open.
        if ($this->input('type') === UserType::Seller->value) {
            return settings()->boolean('system.registration_enabled', true);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = UserType::tryFrom((string) $this->input('type')) ?? UserType::Customer;

        return [
            'first_name' => ['required', 'string', 'min:1', 'max:255'],
            // Nullable by decision (ADR-012) — sole traders and single-name
            // cultures must be representable. min:1, not min:2: a one-letter
            // given name is real.
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],

            /*
            | Uniqueness is scoped to (type, email), matching the database
            | index. A global unique rule would refuse a customer whose address
            | already exists as a seller — which is a legitimate person, not a
            | duplicate. @see docs/authentication.md
            */
            'email' => [
                'required', 'string', 'email:rfc,dns', 'max:255',
                Rule::unique('users', 'email')
                    ->where(fn ($query) => $query->where('type', $type->value)->whereNull('deleted_at')),
            ],

            'password' => ['required', 'confirmed', StrongPassword::for($type)],

            'type' => ['required', 'string', 'in:seller,customer'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],

            // Active-scoped: a disabled locale must not be selectable.
            'language_code' => ['sometimes', 'nullable', 'string', 'size:2',
                Rule::exists('languages', 'code')->where('is_active', true)],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2',
                Rule::exists('countries', 'iso2')->where('is_active', true)],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3',
                Rule::exists('currencies', 'code')->where('is_active', true)],

            'accepted_terms' => ['required', 'accepted'],
        ];
    }

    public function toDto(): RegisterUserDTO
    {
        return new RegisterUserDTO(
            firstName: (string) $this->validated('first_name'),
            lastName: $this->validated('last_name'),
            email: (string) $this->validated('email'),
            password: (string) $this->validated('password'),
            type: UserType::from((string) $this->validated('type')),
            phone: $this->validated('phone'),
            languageCode: $this->validated('language_code'),
            countryCode: $this->validated('country_code'),
            currencyCode: $this->validated('currency_code'),
            acceptedTerms: true,
        );
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // Normalise before the unique rule runs, or "User@Example.com" slips
        // past an index that stores lowercase.
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }
}
