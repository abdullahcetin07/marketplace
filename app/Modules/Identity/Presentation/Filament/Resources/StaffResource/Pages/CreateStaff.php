<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources\StaffResource\Pages;

use App\Models\Admin;
use App\Modules\Identity\Presentation\Filament\Resources\StaffResource;
use App\Modules\Localization\Domain\Contracts\CountryRepositoryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract;
use App\Modules\Localization\Domain\Contracts\TimezoneRepositoryContract;
use App\Shared\Enums\Status;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Provisioning a colleague.
 *
 * THE SAME CREATION PATH AS `marketplace:create-admin`, deliberately: the same
 * columns, the same resolved locale defaults, the same `email_verified_at`
 * stamp. An operator created here and one created from the CLI must be the same
 * account, or the two paths will drift and one of them will be the wrong one.
 *
 * WHY NOT AN ACTION. `RegisterUserAction` refuses admins outright — self-service
 * admin signup is a critical vulnerability and that refusal is load-bearing.
 * There is no admin-creation action to reuse, so the creation stays here in
 * Presentation, exactly as the CLI command and the seller Register page keep
 * theirs. What must NOT live here is a rule, and none does: the escalation
 * guard is `StaffResource::assertRolesGrantable()`, the password policy is
 * `StrongPassword`, and the role names come from config.
 *
 * THE ESCALATION GUARD RUNS BEFORE THE ROW IS WRITTEN. An Admin who forges a
 * `super_admin` value into the payload gets an AuthorizationException and no
 * account at all — not an account that is then quietly missing its role.
 *
 * @see App\Console\Commands\CreateAdminCommand
 * @see App\Filament\Seller\Auth\Register
 */
final class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var array<int, string> $roles */
        $roles = array_values(array_filter(
            (array) ($data['roles'] ?? []),
            static fn (mixed $role): bool => is_string($role) && $role !== '',
        ));

        // Refuse the whole write, not just the role, if it is above the
        // granting operator's own level.
        StaffResource::assertRolesGrantable($roles);

        $lastName = trim((string) ($data['last_name'] ?? ''));

        /*
        | Locale relations are resolved rather than left null so a new operator
        | starts with a concrete, editable preference set — and so creation
        | fails loudly if LocalizationSeeder has not run, which would mean the
        | platform cannot serve a request either. ADR-019: the cached lookups
        | live behind the repositories.
        |
        | `type` is stamped by the Admin subclass on create.
        */
        $admin = Admin::create([
            'first_name' => $data['first_name'],
            'last_name' => $lastName !== '' ? $lastName : null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'status' => Status::Active,
            'language_id' => app(LanguageRepositoryContract::class)->default()->getKey(),
            'currency_id' => app(CurrencyRepositoryContract::class)->default()->getKey(),
            'country_id' => app(CountryRepositoryContract::class)->default()?->getKey(),
            'timezone_id' => app(TimezoneRepositoryContract::class)->default()?->getKey(),
        ]);

        /*
        | Mail is not configured on this platform yet, so the operator sets the
        | initial password and the address is trusted — exactly what the CLI
        | command does. A "send a set-password invitation" flow is the
        | documented follow-up; Core's invitation infrastructure (ADR-031) is
        | org-scoped today and forcing it here would be the wrong shape.
        */
        $admin->forceFill(['email_verified_at' => now()])->save();

        if ($roles !== []) {
            // By NAME, resolved against this user's admin guard. Never by id.
            $admin->syncRoles($roles);
        }

        return $admin;
    }
}
