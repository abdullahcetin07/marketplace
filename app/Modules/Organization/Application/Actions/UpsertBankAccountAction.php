<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Organization\Domain\DTOs\UpsertBankAccountDTO;
use App\Modules\Organization\Domain\Models\OrganizationBankAccount;

/**
 * Set or replace an organization's payout bank account.
 *
 * Operates on the PRIMARY account (the schema allows more, the app enables one
 * for now), upserting on `(organization_id, is_primary)`. The IBAN is stored
 * through the model's `encrypted` cast — ciphertext at rest, excluded from the
 * audit trail; the change to the OTHER fields (holder, bank, currency) is
 * audited normally, attributed to the acting member.
 *
 * Changing the account resets `verified_at`: a new number has not been verified.
 */
final class UpsertBankAccountAction extends BaseAction
{
    public function __construct(
        private readonly CurrencyRepositoryContract $currencies,
    ) {}

    public function handle(mixed ...$arguments): OrganizationBankAccount
    {
        /** @var UpsertBankAccountDTO $data */
        $data = $arguments[0];

        $account = OrganizationBankAccount::query()->firstOrNew([
            'organization_id' => $data->organizationId,
            'is_primary' => true,
        ]);

        // A changed IBAN is an unverified IBAN again.
        if ($account->exists && $account->iban !== $data->normalisedIban()) {
            $account->verified_at = null;
        }

        $account->forceFill([
            'organization_id' => $data->organizationId,
            'is_primary' => true,
            'account_holder' => $data->accountHolder,
            'iban' => $data->normalisedIban(),
            'bank_name' => $data->bankName,
            'currency_id' => $this->currencies->findByCode($data->currencyCode)?->getKey(),
        ])->save();

        return $account;
    }
}
