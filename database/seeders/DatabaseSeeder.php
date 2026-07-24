<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Modules\Localization\Seeders\LocalizationSeeder;
use Database\Modules\Organization\Seeders\OrganizationPlanSeeder;
use Database\Modules\Settings\Seeders\SettingsSeeder;
use Illuminate\Database\Seeder;

/**
 * Entry point for `php artisan db:seed`.
 *
 * Only structural seeders run here. There is no demo data — the foundation
 * must be verifiable without fixtures.
 *
 * ORDER IS LOAD-BEARING. Localization runs first: without a default language
 * and a default currency, the language and currency repository defaults throw
 * and every subsequent request fails. Settings depend on nothing but are read
 * by later modules.
 *
 * Every seeder here is idempotent and safe on every deploy.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Locale data the platform cannot boot without.
            LocalizationSeeder::class,

            // Business-configurable settings, registered with their defaults.
            SettingsSeeder::class,

            // Subscription tiers that set store allowances (ADR-028).
            OrganizationPlanSeeder::class,

            // Roles and permissions, derived from PermissionRegistry.
            RolePermissionSeeder::class,
        ]);

        /*
        | The first admin is created interactively rather than seeded, so no
        | environment ever ships with a known-password account:
        |
        |   php artisan marketplace:create-admin --super
        |
        | @see App\Console\Commands\CreateAdminCommand
        */
    }
}
