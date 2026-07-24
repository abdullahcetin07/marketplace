<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests\Admin;

use App\Core\Presentation\Requests\BaseRequest;
use App\Models\User;
use App\Modules\Identity\Domain\DTOs\AdminUpdateUserDTO;
use App\Shared\Enums\Status;
use Illuminate\Validation\Rule;

/**
 * An administrator editing another user's account.
 *
 * Authorised through UserPolicy::update — which allows the self-edit case, the
 * ability check, AND the privilege-escalation guard that stops a lesser admin
 * touching a super-admin. The controller never re-checks; the policy is the one
 * place the rule lives.
 *
 * PATCH: every field `sometimes`. `reason` is optional and, when given, is
 * written into the audit entry.
 */
final class AdminUpdateUserRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && $this->actor()?->can('update', $target) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],

            // An admin may suspend or reactivate. Pending/other lifecycle
            // states are set by their own flows, not a free-form admin edit.
            'status' => ['sometimes', 'required', Rule::in([Status::Active->value, Status::Suspended->value])],

            'language_code' => ['sometimes', 'nullable', 'string', 'size:2',
                Rule::exists('languages', 'code')->where('is_active', true)],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2',
                Rule::exists('countries', 'iso2')->where('is_active', true)],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3',
                Rule::exists('currencies', 'code')->where('is_active', true)],
            'timezone_name' => ['sometimes', 'nullable', 'string', 'max:64',
                Rule::exists('timezones', 'name')->where('is_active', true)],

            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function toDto(): AdminUpdateUserDTO
    {
        $fields = ['first_name', 'last_name', 'phone', 'status',
            'language_code', 'country_code', 'currency_code', 'timezone_name'];

        $present = array_values(array_intersect($fields, array_keys($this->all())));

        return new AdminUpdateUserDTO(
            firstName: $this->input('first_name'),
            lastName: $this->input('last_name'),
            phone: $this->input('phone'),
            status: $this->input('status'),
            languageCode: $this->input('language_code'),
            countryCode: $this->input('country_code'),
            currencyCode: $this->input('currency_code'),
            timezoneName: $this->input('timezone_name'),
            reason: $this->input('reason'),
            present: $present,
        );
    }
}
