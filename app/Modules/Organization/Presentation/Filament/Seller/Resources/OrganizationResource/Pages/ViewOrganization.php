<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\Pages;

use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Organization\Application\Actions\SubmitKycAction;
use App\Modules\Organization\Application\Actions\UpsertBankAccountAction;
use App\Modules\Organization\Domain\DTOs\SubmitKycDTO;
use App\Modules\Organization\Domain\DTOs\UpsertBankAccountDTO;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationBankAccount;
use App\Modules\Organization\Domain\Models\OrganizationKyc;
use App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\Rule;

/**
 * The company detail page — the hub of seller onboarding.
 *
 * Editing the profile, submitting KYC and setting the payout account each live
 * behind their own header action because each is a DIFFERENT capability and a
 * different module Action; collapsing them into one save would blur three
 * authorization decisions into one.
 */
final class ViewOrganization extends ViewRecord
{
    protected static string $resource = OrganizationResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            $this->submitKycAction(),
            $this->bankAccountAction(),
        ];
    }

    /**
     * Company verification — the schema is `SubmitKycRequest` field-for-field,
     * gated by the same `manageKyc` capability, delegating to the same
     * `SubmitKycAction`. The action upserts one row per organization, so this
     * single button covers both the first submission and a later correction.
     *
     * @see App\Modules\Organization\Presentation\Controllers\Api\OrganizationController::submitKyc()
     */
    private function submitKycAction(): Actions\Action
    {
        return Actions\Action::make('submitKyc')
            ->label(__('organization.kyc.action'))
            ->icon('heroicon-o-identification')
            ->modalHeading(__('organization.kyc.title'))
            ->modalSubmitActionLabel(__('organization.kyc.action'))
            ->visible(fn (): bool => auth()->user()?->can('manageKyc', $this->getRecord()) === true)
            ->form([
                Forms\Components\TextInput::make('tax_number')
                    ->label(__('organization.kyc.tax_number'))
                    ->maxLength(64),

                Forms\Components\TextInput::make('registration_number')
                    ->label(__('organization.kyc.registration_number'))
                    ->maxLength(64),

                Forms\Components\TextInput::make('authorized_person_name')
                    ->label(__('organization.kyc.authorized_person_name'))
                    ->maxLength(255),

                /*
                | The stored national id is NEVER rendered back into the form —
                | it is encrypted at rest and audit-excluded precisely so it
                | does not travel to a browser. The field therefore starts
                | empty and "left blank" means "keep what is on file", handled
                | below; it does not mean "erase it".
                */
                Forms\Components\TextInput::make('authorized_person_national_id')
                    ->label(__('organization.kyc.national_id'))
                    ->helperText(fn (): string => $this->kyc()?->authorized_person_national_id !== null
                        ? __('organization.kyc.national_id_on_file')
                        : __('organization.kyc.national_id_hint'))
                    ->maxLength(64),

                // Jurisdiction-specific identifiers (MERSİS, tax office, …)
                // ride in the free bag rather than forcing a migration per
                // country — the same contract the DTO documents.
                Forms\Components\KeyValue::make('metadata')
                    ->label(__('organization.kyc.metadata'))
                    ->keyLabel(__('organization.kyc.metadata_key'))
                    ->valueLabel(__('organization.kyc.metadata_value')),
            ])
            ->fillForm(fn (): array => [
                'tax_number' => $this->kyc()?->tax_number,
                'registration_number' => $this->kyc()?->registration_number,
                'authorized_person_name' => $this->kyc()?->authorized_person_name,
                'authorized_person_national_id' => null,
                'metadata' => $this->kyc()?->metadata ?? [],
            ])
            ->action(function (array $data): void {
                /** @var Organization $record */
                $record = $this->getRecord();

                app(SubmitKycAction::class)->run(new SubmitKycDTO(
                    organizationId: (int) $record->getKey(),
                    taxNumber: $data['tax_number'] ?? null,
                    registrationNumber: $data['registration_number'] ?? null,
                    authorizedPersonName: $data['authorized_person_name'] ?? null,
                    // Blank keeps the value on file; the action force-fills
                    // every column, so passing null here would wipe it.
                    authorizedPersonNationalId: filled($data['authorized_person_national_id'] ?? null)
                        ? (string) $data['authorized_person_national_id']
                        : $this->kyc()?->authorized_person_national_id,
                    metadata: (array) ($data['metadata'] ?? []),
                ));

                $record->unsetRelation('kyc');
                $this->refreshFormData([]);

                Notification::make()->title(__('organization.kyc_submitted'))->success()->send();
            });
    }

    /**
     * The payout account — schema and gate both mirror
     * `UpsertBankAccountRequest`. The action upserts the PRIMARY account, so
     * this one button both sets the first account and replaces it later.
     *
     * @see App\Modules\Organization\Presentation\Controllers\Api\BankAccountController::update()
     */
    private function bankAccountAction(): Actions\Action
    {
        return Actions\Action::make('bankAccount')
            ->label(__('organization.bank.action'))
            ->icon('heroicon-o-banknotes')
            ->modalHeading(__('organization.bank.title'))
            ->modalSubmitActionLabel(__('organization.bank.action'))
            // Gated on the account, existing or a stub bound to this company —
            // the request does exactly this, because the capability lives on
            // the membership and there may be no account row yet.
            ->visible(fn (): bool => auth()->user()?->can('update', $this->bankAccountOrStub()) === true)
            ->form([
                Forms\Components\TextInput::make('account_holder')
                    ->label(__('organization.bank.account_holder'))
                    ->required()
                    ->maxLength(255),

                /*
                | Required on every save, and never prefilled. The stored IBAN
                | is encrypted and audit-excluded, so the form cannot render it
                | back; the masked form is shown as context instead. That makes
                | replacing the payout account a deliberate re-entry rather than
                | a one-character edit of a rendered secret — and it matches the
                | API, where the IBAN is required on every PUT.
                */
                Forms\Components\TextInput::make('iban')
                    ->label(__('organization.bank.iban'))
                    ->required()
                    ->minLength(15)
                    ->maxLength(42)
                    ->helperText(fn (): string => $this->bankAccount() !== null
                        ? __('organization.bank.iban_on_file', ['iban' => $this->bankAccount()->maskedIban()])
                        : __('organization.bank.iban_hint')),

                Forms\Components\TextInput::make('bank_name')
                    ->label(__('organization.bank.bank_name'))
                    ->maxLength(255),

                Forms\Components\Select::make('currency_code')
                    ->label(__('organization.bank.currency'))
                    ->options(fn (): array => Currency::query()
                        ->where('is_active', true)
                        ->orderBy('code')
                        ->pluck('name', 'code')
                        ->all())
                    ->searchable()
                    ->required()
                    ->rule(Rule::exists('currencies', 'code')->where('is_active', true)),
            ])
            ->fillForm(function (): array {
                /** @var Organization $record */
                $record = $this->getRecord();
                $account = $this->bankAccount();

                return [
                    'account_holder' => $account?->account_holder,
                    'iban' => null,
                    'bank_name' => $account?->bank_name,
                    // Falls back to the company's own currency — payouts in the
                    // trading currency are the common case.
                    'currency_code' => $account?->currency?->code ?? $record->currency?->code,
                ];
            })
            ->action(function (array $data): void {
                /** @var Organization $record */
                $record = $this->getRecord();

                app(UpsertBankAccountAction::class)->run(new UpsertBankAccountDTO(
                    organizationId: (int) $record->getKey(),
                    accountHolder: (string) $data['account_holder'],
                    iban: (string) $data['iban'],
                    bankName: $data['bank_name'] ?? null,
                    currencyCode: (string) $data['currency_code'],
                ));

                $record->unsetRelation('bankAccount');
                $this->refreshFormData([]);

                Notification::make()->title(__('organization.bank_account_updated'))->success()->send();
            });
    }

    private function bankAccount(): ?OrganizationBankAccount
    {
        /** @var Organization $record */
        $record = $this->getRecord();

        return $record->bankAccount;
    }

    /**
     * The account the policy is asked about: the real one, or an unsaved stub
     * carrying the organization id so the membership still resolves.
     */
    private function bankAccountOrStub(): OrganizationBankAccount
    {
        /** @var Organization $record */
        $record = $this->getRecord();

        return $this->bankAccount()
            ?? new OrganizationBankAccount(['organization_id' => $record->getKey()]);
    }

    private function kyc(): ?OrganizationKyc
    {
        /** @var Organization $record */
        $record = $this->getRecord();

        return $record->kyc;
    }
}
