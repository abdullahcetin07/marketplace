<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use App\Models\User;
use Database\Modules\Localization\Seeders\LocalizationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
        | No test may reach the network. Without this a forgotten HTTP call
        | makes the suite slow, flaky and dependent on someone else's uptime —
        | and StrongPassword's uncompromised() check would call Have I Been
        | Pwned on every user factory.
        */
        Http::preventStrayRequests();
    }

    /**
     * Seed the locale data the platform cannot boot without.
     *
     * The language and currency repositories use firstOrFail on the default, so any
     * test touching locale, money formatting or registration needs this. It is
     * NOT global: most tests do not need it, and seeding for every test costs
     * more than it saves.
     */
    protected function seedPlatform(): void
    {
        $this->seed(LocalizationSeeder::class);
    }

    /**
     * Seed roles and permissions. Call from tests that assert on authorisation.
     */
    protected function seedRolesAndPermissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Both, for feature tests that exercise a full request.
     */
    protected function seedAll(): void
    {
        $this->seedPlatform();
        $this->seedRolesAndPermissions();
    }

    /**
     * Sign in as an administrator on the `admin` guard.
     */
    protected function actingAsAdmin(?Admin $admin = null): Admin
    {
        $admin ??= Admin::factory()->create();

        // Hand the guard a fully-hydrated row, exactly as the user provider
        // does in production. A freshly factory-built model only holds the
        // columns the factory set, so reading a nullable column it omitted
        // (e.g. two_factor_secret) throws under preventAccessingMissingAttributes.
        // refresh() reloads every column in place, preserving object identity.
        $admin->refresh();

        $this->actingAs($admin, 'admin');

        return $admin;
    }

    protected function actingAsSeller(?Seller $seller = null): Seller
    {
        $seller ??= Seller::factory()->create();

        // See actingAsAdmin: hydrate the full row so strict mode does not throw
        // on a nullable column the factory omitted.
        $seller->refresh();

        $this->actingAs($seller, 'seller');

        return $seller;
    }

    protected function actingAsCustomer(?Customer $customer = null): Customer
    {
        $customer ??= Customer::factory()->create();

        // See actingAsAdmin: hydrate the full row so strict mode does not throw
        // on a nullable column the factory omitted.
        $customer->refresh();

        $this->actingAs($customer, 'customer');

        return $customer;
    }

    /**
     * Grant a permission directly, bypassing roles — for testing a policy in
     * isolation from whichever role happens to bundle the permission today.
     */
    protected function grant(User $user, string ...$permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, $user->guardName());
        }

        $user->givePermissionTo($permissions);

        return $user->refresh();
    }
}
