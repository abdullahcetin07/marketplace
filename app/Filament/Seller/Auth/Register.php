<?php

declare(strict_types=1);

namespace App\Filament\Seller\Auth;

use App\Models\Seller;
use App\Modules\Localization\Domain\Contracts\CountryRepositoryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract;
use App\Modules\Localization\Domain\Contracts\TimezoneRepositoryContract;
use App\Shared\Enums\Status;
use App\Shared\Enums\UserType;
use App\Shared\Rules\StrongPassword;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\Register as BaseRegister;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Self-service merchant signup at /seller/register.
 *
 * WHY THIS PAGE EXISTS AT ALL: Filament's stock Register page posts a single
 * `name` field and calls `Model::create(['name' => ...])`. This platform split
 * that column into `first_name` + `last_name` (ADR-012) and deliberately keeps
 * neither `name` nor the computed `display_name` mass assignable, so the stock
 * page cannot create a user here — it throws MassAssignmentException. The fix
 * belongs in Presentation: the form is what is wrong about it, not the model.
 *
 * WHY IT LIVES OUTSIDE `app/Filament/Seller/Pages`: SellerPanelProvider
 * discovers pages from that directory and registers every one of them in the
 * navigation. A registration page in there would become a menu item for
 * already-signed-in sellers. Auth pages are wired by name
 * (`->registration(...)`), never discovered.
 *
 * @see App\Providers\Filament\SellerPanelProvider
 * @see App\Console\Commands\CreateAdminCommand  the same creation path, for admins
 */
final class Register extends BaseRegister
{
    public function form(Form $form): Form
    {
        return $form->schema([
            $this->getFirstNameFormComponent(),
            $this->getLastNameFormComponent(),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    protected function getFirstNameFormComponent(): Component
    {
        return TextInput::make('first_name')
            ->label(__('users.first_name'))
            ->required()
            ->maxLength(255)
            ->autofocus();
    }

    /**
     * Optional by decision (ADR-012) — the platform serves sole traders and
     * markets where a single given name is normal.
     */
    protected function getLastNameFormComponent(): Component
    {
        return TextInput::make('last_name')
            ->label(__('users.last_name'))
            ->maxLength(255);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::pages/auth/register.form.email.label'))
            ->email()
            ->required()
            ->maxLength(255)
            /*
            | Uniqueness is scoped to (type, email), not to the whole table.
            | One human is routinely both a customer and a merchant here — the
            | address already existing as a customer or an admin must not block
            | a seller signup. Soft-deleted rows are excluded for the same
            | reason the guard's provider ignores them: a closed account does
            | not reserve its address forever.
            |
            | The default `->unique($this->getUserModel())` would resolve the
            | Seller model and pick up its `type` global scope, but only via
            | the model's default query — the rule builds a bare table query,
            | so the scope is stated here explicitly.
            */
            ->unique(
                table: 'users',
                column: 'email',
                modifyRuleUsing: static fn (Unique $rule): Unique => $rule
                    ->where('type', UserType::Seller->value)
                    ->whereNull('deleted_at'),
            );
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('filament-panels::pages/auth/register.form.password.label'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            // One policy, one place. Filament's default is Laravel's generic
            // Password::default(), which knows nothing about the staff/customer
            // asymmetry this platform draws.
            ->rule(StrongPassword::for(UserType::Seller))
            ->same('passwordConfirmation')
            ->validationAttribute(__('filament-panels::pages/auth/register.form.password.validation_attribute'));
        // Deliberately NOT dehydrated through Hash::make() as the stock page
        // does: `password` is cast `hashed` on the User model, so hashing here
        // would be the second source of truth for how a credential is stored.
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRegistration(array $data): Model
    {
        $lastName = trim((string) ($data['last_name'] ?? ''));

        /*
        | Locale relations are resolved rather than left null so a new merchant
        | starts with a concrete, editable preference set — and so signup fails
        | loudly if LocalizationSeeder has not run, which would mean the
        | platform cannot serve a request either. ADR-019: the cached lookups
        | live behind the repositories.
        |
        | No `email_verified_at`: the panel's ->emailVerification() sends the
        | link and the base page's register() triggers it. `type` is stamped by
        | the Seller subclass on create, and `status` starts Active — approval
        | is an Organization-level gate, not an identity-level one.
        */
        return Seller::create([
            'first_name' => $data['first_name'],
            'last_name' => $lastName !== '' ? $lastName : null,
            'email' => $data['email'],
            'password' => $data['password'],
            'status' => Status::Active,
            'language_id' => app(LanguageRepositoryContract::class)->default()->getKey(),
            'currency_id' => app(CurrencyRepositoryContract::class)->default()->getKey(),
            'country_id' => app(CountryRepositoryContract::class)->default()?->getKey(),
            'timezone_id' => app(TimezoneRepositoryContract::class)->default()?->getKey(),
        ]);
    }
}
