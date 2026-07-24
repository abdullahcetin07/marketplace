<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Admin;
use App\Modules\Localization\Domain\Contracts\CountryRepositoryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Localization\Domain\Contracts\LanguageRepositoryContract;
use App\Modules\Localization\Domain\Contracts\TimezoneRepositoryContract;
use App\Shared\Enums\Status;
use App\Shared\Rules\StrongPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the first administrator interactively.
 *
 * WHY NOT A SEEDER: a seeded admin means every environment ships with an
 * account whose password is in version control. The first credential on a
 * platform has to be created by a person, once, and never written down in a
 * repository.
 *
 * The password is prompted for (never passed as an argument, where it would
 * land in shell history and process listings) and validated against the full
 * staff password policy.
 */
final class CreateAdminCommand extends Command
{
    protected $signature = 'marketplace:create-admin
                            {--first-name= : Given name}
                            {--last-name= : Family name (optional)}
                            {--email= : Login email}
                            {--super : Grant the platform super-admin role}';

    protected $description = 'Create an administrator account interactively';

    public function handle(): int
    {
        $firstName = $this->option('first-name') ?: text(
            label: 'First name',
            required: true,
        );

        // Optional by decision (ADR-012) — an empty answer becomes null, not ''.
        $lastName = $this->option('last-name') ?: text(
            label: 'Last name',
            hint: 'Optional — leave blank if you have only one name',
            required: false,
        );

        $lastName = is_string($lastName) && trim($lastName) !== '' ? trim($lastName) : null;

        $email = $this->option('email') ?: text(
            label: 'Email',
            required: true,
        );

        $password = password(
            label: 'Password',
            required: true,
        );

        $confirmation = password(
            label: 'Confirm password',
            required: true,
        );

        $validator = Validator::make([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            // Uniqueness is scoped to the admin type: the same address may
            // legitimately already exist as a customer. @see users migration.
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', StrongPassword::staff()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (Admin::query()->where('email', $email)->exists()) {
            $this->error("An administrator with the email {$email} already exists.");

            return self::FAILURE;
        }

        /*
        | Locale relations are optional — null means "use the platform
        | default". Resolving them here rather than leaving null gives the
        | first admin a concrete, editable preference set, and fails loudly if
        | LocalizationSeeder has not run (which would mean the platform cannot
        | serve a request either).
        */
        $admin = Admin::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => $password,
            'status' => Status::Active,
            // ADR-019: cached lookups live behind the repositories.
            'language_id' => app(LanguageRepositoryContract::class)->default()->getKey(),
            'currency_id' => app(CurrencyRepositoryContract::class)->default()->getKey(),
            'country_id' => app(CountryRepositoryContract::class)->default()?->getKey(),
            'timezone_id' => app(TimezoneRepositoryContract::class)->default()?->getKey(),
        ]);

        $admin->forceFill(['email_verified_at' => now()])->save();

        if ($this->option('super')) {
            $admin->assignRole(config('marketplace.roles.super_admin'));
            $this->line('Granted the super-admin role.');
        }

        $this->info("Administrator created: {$admin->email}");
        $this->line("Sign in at ".config('app.url')."/admin");

        if (! $this->option('super')) {
            $this->newLine();
            $this->comment('No role was assigned. Grant one with --super, or from the admin panel.');
        }

        return self::SUCCESS;
    }
}
