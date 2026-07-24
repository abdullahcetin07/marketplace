<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Identity\Domain\DTOs\UpdateProfileDTO;
use Illuminate\Validation\Rule;

/**
 * Profile update input.
 *
 * PATCH: every field is `sometimes`, so a request may carry any subset. Locale
 * codes are validated against ACTIVE lookup rows — a user must not be able to
 * select a language the operator disabled.
 *
 * No email field. @see the spec §5.4.
 */
final class UpdateProfileRequest extends BaseRequest
{
    protected ?string $dto = UpdateProfileDTO::class;

    /**
     * A user may edit their own profile. The route is behind `auth:sanctum`, so
     * `current_actor()` is the authenticated user editing themselves.
     */
    public function authorize(): bool
    {
        return current_actor() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            // last_name is nullable (ADR-012): `sometimes` + `nullable` lets a
            // user clear a surname they no longer want shown.
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],

            'language_code' => ['sometimes', 'nullable', 'string', 'size:2',
                Rule::exists('languages', 'code')->where('is_active', true)],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2',
                Rule::exists('countries', 'iso2')->where('is_active', true)],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3',
                Rule::exists('currencies', 'code')->where('is_active', true)],
            'timezone_name' => ['sometimes', 'nullable', 'string', 'max:64',
                Rule::exists('timezones', 'name')->where('is_active', true)],
        ];
    }

    public function toDto(): UpdateProfileDTO
    {
        // The set of keys actually present — the PATCH signal. Computed from the
        // raw input, not the validated set, so a present-but-null field counts.
        $present = array_values(array_intersect(
            ['first_name', 'last_name', 'phone', 'language_code', 'country_code', 'currency_code', 'timezone_name'],
            array_keys($this->all()),
        ));

        return new UpdateProfileDTO(
            firstName: $this->input('first_name'),
            lastName: $this->input('last_name'),
            phone: $this->input('phone'),
            languageCode: $this->input('language_code'),
            countryCode: $this->input('country_code'),
            currencyCode: $this->input('currency_code'),
            timezoneName: $this->input('timezone_name'),
            present: $present,
        );
    }
}
