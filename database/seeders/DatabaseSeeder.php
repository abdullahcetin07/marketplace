<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Modules\Catalog\Seeders\TaxRateSeeder;
use Database\Modules\Localization\Seeders\LocalizationSeeder;
use Database\Modules\Organization\Seeders\OrganizationPlanSeeder;
use Database\Modules\Payment\Seeders\CommissionRuleSeeder;
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

            /*
            | KDV brackets (ADR-056). Structural, not editorial: the product
            | authoring form REQUIRES a bracket, so a platform without them blocks
            | every seller — the LocalizationSeeder reasoning. It also backfills
            | `products.tax_rate_id`, which is the other half of that migration.
            */
            TaxRateSeeder::class,

            /*
            | The platform's default commission rate (ADR-061). Structural for the
            | same reason: with no rules at all the resolver takes 0%, which is a
            | defensible state for a platform that has not decided yet and is not
            | what anyone launching a marketplace means — and a silent 0% is
            | discovered in a month's accounts.
            */
            CommissionRuleSeeder::class,

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
