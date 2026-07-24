<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Organization\Domain\DTOs\UpsertBankAccountDTO;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationBankAccount;
use Illuminate\Validation\Rule;

/**
 * Set the payout bank account. Authorised by the BankAccountUpdate capability.
 * The account may not exist yet, so the policy is checked against the existing
 * account or a stub bound to the organization — either way it resolves the
 * actor's membership of that org.
 */
final class UpsertBankAccountRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $org = $this->route('organization');
        if (! $org instanceof Organization) {
            return false;
        }

        $account = $org->bankAccount()->first()
            ?? new OrganizationBankAccount(['organization_id' => $org->getKey()]);

        return $this->actor()?->can('update', $account) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_holder' => ['required', 'string', 'max:255'],
            'iban' => ['required', 'string', 'min:15', 'max:42'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency_code' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
        ];
    }

    public function toDto(int $organizationId): UpsertBankAccountDTO
    {
        return new UpsertBankAccountDTO(
            organizationId: $organizationId,
            accountHolder: (string) $this->validated('account_holder'),
            iban: (string) $this->validated('iban'),
            bankName: $this->validated('bank_name'),
            currencyCode: (string) $this->validated('currency_code'),
        );
    }
}
