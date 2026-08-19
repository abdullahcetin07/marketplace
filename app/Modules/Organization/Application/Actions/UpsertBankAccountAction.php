<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Organization\Domain\DTOs\UpsertBankAccountDTO;
use App\Modules\Organization\Domain\Events\OrganizationBankAccountChanged;
use App\Modules\Organization\Domain\Models\Organization;
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
    private bool $announceChange = false;

    private ?string $previousIban = null;

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

        /*
        | **A CHANGED IBAN IS AN UNVERIFIED IBAN AGAIN — AND NOW A LOUD ONE**
        | (security audit, 2026-08-18). The payout destination is the most valuable
        | write in the seller panel; the audit closed the route a Manager used to
        | reach it, and this makes the change itself visible rather than silent.
        */
        $ibanChanged = $account->exists && $account->iban !== $data->normalisedIban();

        if ($ibanChanged) {
            $account->verified_at = null;
            $this->previousIban = (string) $account->iban;
        }

        $this->announceChange = $ibanChanged;

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

    protected function after(mixed $result, mixed ...$arguments): void
    {
        if (! $this->announceChange) {
            return;
        }

        /** @var OrganizationBankAccount $result */
        $organizationUuid = (string) Organization::query()
            ->whereKey($result->organization_id)->value('uuid');

        event(new OrganizationBankAccountChanged(
            organizationId: (int) $result->organization_id,
            organizationUuid: $organizationUuid,
            previousIbanMasked: $this->previousIban === null ? null : $this->mask($this->previousIban),
            newIbanMasked: $this->mask((string) $result->iban),
            actorId: current_actor()?->getKey(),
        ));
    }

    /**
     * Last four digits only.
     *
     * **THE TRAIL NEEDS TO SHOW THAT THE DESTINATION MOVED, NOT WHERE TO.** A full
     * IBAN in the audit log would make that log a second copy of every seller's
     * bank details, readable by everybody who may read audits.
     */
    private function mask(string $iban): string
    {
        return str_repeat('*', max(0, mb_strlen($iban) - 4)).mb_substr($iban, -4);
    }
}
