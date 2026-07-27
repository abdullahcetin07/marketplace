# Work order — custom seller registration page (Filament v3.3)

**Disposable. Delete when done.** For the server-side Claude session (has `vendor/`,
can run the app and test live). Implement, verify in the browser AND with a test,
commit, push.

## Problem
The seller panel (`app/Providers/Filament/SellerPanelProvider.php:50`) enables
Filament's DEFAULT registration (`->registration()`). Filament's stock Register page
submits a `name` field and calls `Seller::create(['name' => ..., ...])`. Our user
model uses `first_name` / `last_name` (ADR-012) and does NOT make `name` fillable, so
`/seller/register` throws `MassAssignmentException: Add fillable property [name]`.
Registration is INTENDED to exist here (see the provider docblock — "sellers onboard
themselves and are then approved"); it just needs a page that matches our model.

## Rules
- Presentation layer only. Do NOT touch the frozen domain/models or add `name` to
  `$fillable` (that would fight ADR-012). Fix the Filament page, not the model.
- Mirror the proven creation path in `app/Console/Commands/CreateAdminCommand.php`
  (same fields, same locale-default resolution) but for `Seller` and WITHOUT
  pre-setting `email_verified_at` (the panel's `->emailVerification()` handles that).
- Password policy: `App\Shared\Rules\StrongPassword::for(App\Shared\Enums\UserType::Seller)`.
- Verify every Filament API name against the INSTALLED Filament v3.3 (`vendor/filament`)
  before finalising — method signatures below are a guide, not gospel.

## Implement

### 1. New page: `app/Filament/Seller/Auth/Register.php`
Namespace `App\Filament\Seller\Auth` — deliberately OUTSIDE the panel's
`discoverPages(in: app_path('Filament/Seller/Pages'), ...)` path so it is never
registered as a navigation page. Extend `Filament\Pages\Auth\Register`.

Reference sketch (adapt to the exact v3.3 API):
```php
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
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\Register as BaseRegister;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

final class Register extends BaseRegister
{
    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('first_name')
                ->label(__('Ad'))->required()->maxLength(255)->autofocus(),
            TextInput::make('last_name')
                ->label(__('Soyad'))->maxLength(255), // optional (ADR-012)
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    protected function getEmailFormComponent(): TextInput
    {
        // Uniqueness is scoped to (type, email): the same address may already
        // exist as a customer or admin and that must NOT block a seller signup.
        return TextInput::make('email')
            ->label(__('E-posta'))
            ->email()->required()->maxLength(255)
            ->unique(table: 'users', column: 'email', modifyRuleUsing: fn ($rule) =>
                $rule->where('type', UserType::Seller->value)->whereNull('deleted_at'));
    }

    protected function getPasswordFormComponent(): TextInput
    {
        return TextInput::make('password')
            ->label(__('Şifre'))
            ->password()->required()
            ->rule(StrongPassword::for(UserType::Seller))
            ->same('passwordConfirmation')
            ->validationAttribute(__('şifre'));
        // Do NOT hash here — the User model casts `password` as hashed.
    }

    protected function handleRegistration(array $data): Model
    {
        return Seller::create([
            'first_name'  => $data['first_name'],
            'last_name'   => ($data['last_name'] ?? '') !== '' ? $data['last_name'] : null,
            'email'       => $data['email'],
            'password'    => $data['password'], // hashed by the model cast
            'status'      => Status::Active,
            'language_id' => app(LanguageRepositoryContract::class)->default()->getKey(),
            'currency_id' => app(CurrencyRepositoryContract::class)->default()->getKey(),
            'country_id'  => app(CountryRepositoryContract::class)->default()?->getKey(),
            'timezone_id' => app(TimezoneRepositoryContract::class)->default()?->getKey(),
        ]);
        // No email_verified_at — the panel's ->emailVerification() sends the link.
        // `type` is stamped automatically by the Seller subclass on create.
    }
}
```
Notes to verify in v3.3:
- `getPasswordConfirmationFormComponent()` provides the `passwordConfirmation` field —
  keep using it so `->same('passwordConfirmation')` matches.
- If `->unique(modifyRuleUsing:)` signature differs in this version, use a plain
  `Rule::unique('users','email')->where('type','seller')->whereNull('deleted_at')`
  via `->rules([...])`. The REQUIREMENT is: email unique among sellers only.
- Confirm `StrongPassword::for()` returns something acceptable to `->rule()`
  (it returns an `Illuminate\Validation\Rules\Password`). Its `uncompromised()` makes
  an HTTP call to HIBP — fine on the server (has network).

### 2. Wire it up
`app/Providers/Filament/SellerPanelProvider.php` line 50:
```php
->registration(\App\Filament\Seller\Auth\Register::class)
```

## Verify (all three)
1. **Browser:** go to `https://test.raftabul.com/seller/register`, fill Ad / Soyad /
   e-posta / şifre (use a strong password: 12+ chars, mixed case, a number), submit.
   It must create the seller and move to the email-verification step — NO 500, NO
   MassAssignment error. Then confirm you can reach `/seller` once verified/logged in.
2. **DB:** the new row in `users` has `type = 'seller'`, `first_name`/`last_name` set,
   `name` untouched, password hashed.
3. **Test:** add a Feature/Livewire test under `tests/` that renders the seller
   Register page and submits valid data, asserting a `Seller` is created with the right
   `type`, `first_name`, and no MassAssignment error. Use Filament's Livewire testing
   helpers (`Livewire::test(\App\Filament\Seller\Auth\Register::class)` within the
   seller panel context). Then run the full suite — it must stay green (was 357/0).

## Finish
- Commit (one commit: page + provider line + test), push `origin main`.
- `git rm FIX_SELLER_REGISTER.md`, commit, push.
- Report the final `Tests:` line and confirm the browser flow worked.
