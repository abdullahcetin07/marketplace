<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Seller;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Permission Registry & Guard-Scoped Authorisation
|--------------------------------------------------------------------------
|
| Proves the two authorisation rules the platform is built on:
|   * permissions are derived and dynamic, not hand-maintained
|   * roles are addressed by name, never by id
|
| @see docs/authorization.md
|
*/

it('generates the full verb set for every registered resource', function (): void {
    $adminPermissions = PermissionRegistry::forGuard(UserType::Admin->guard());

    foreach (PermissionRegistry::VERBS as $verb) {
        expect($adminPermissions)->toContain("user.{$verb}");
    }
});

it('scopes permissions to the guard that declared them', function (): void {
    $admin = PermissionRegistry::forGuard(UserType::Admin->guard());
    $seller = PermissionRegistry::forGuard(UserType::Seller->guard());

    expect($admin)->toContain('panel.admin.access')
        ->and($admin)->not->toContain('panel.seller.access')
        ->and($seller)->toContain('panel.seller.access')
        ->and($seller)->not->toContain('panel.admin.access');
});

it('is idempotent when synced repeatedly', function (): void {
    PermissionRegistry::sync();
    $countAfterFirst = Permission::query()->count();

    $secondRun = PermissionRegistry::sync();

    expect($secondRun)->toBe([])
        ->and(Permission::query()->count())->toBe($countAfterFirst);
});

it('creates the same permission name separately per guard', function (): void {
    PermissionRegistry::sync();

    $adminUserView = Permission::query()
        ->where(['name' => 'user.view', 'guard_name' => 'admin'])
        ->first();

    expect($adminUserView)->not->toBeNull();

    // Registering `user` for the seller guard would create a DISTINCT row —
    // same name, different guard, different meaning.
    Permission::findOrCreate('user.view', 'seller');

    expect(Permission::query()->where('name', 'user.view')->count())->toBe(2);
});

it('seeds every configured role under the right guard', function (): void {
    $this->seedRolesAndPermissions();

    $expected = [
        'super_admin' => 'admin',
        'editor' => 'admin',
        'category_manager' => 'admin',
        'seller' => 'seller',
        'seller_employee' => 'seller',
        'customer' => 'customer',
    ];

    foreach ($expected as $configKey => $guard) {
        $name = config("marketplace.roles.{$configKey}");

        expect(Role::query()->where(['name' => $name, 'guard_name' => $guard])->exists())
            ->toBeTrue("Role '{$name}' missing on guard '{$guard}'");
    }
});

it('resolves roles by name rather than id', function (): void {
    $this->seedRolesAndPermissions();

    $admin = Admin::factory()->create();
    $admin->assignRole(config('marketplace.roles.super_admin'));

    expect($admin->fresh()->isSuperAdmin())->toBeTrue();
});

it('does not let a seller role satisfy an admin permission check', function (): void {
    $this->seedRolesAndPermissions();

    $seller = Seller::factory()->create();
    $seller->assignRole(config('marketplace.roles.seller'));

    expect($seller->hasPermissionTo('panel.seller.access', 'seller'))->toBeTrue()
        ->and($seller->hasPermissionTo('panel.admin.access', 'seller'))->toBeFalse();
});

it('reports permissions that exist but are no longer declared', function (): void {
    PermissionRegistry::sync();

    Permission::findOrCreate('legacy.thing', 'admin');

    expect(PermissionRegistry::orphans()->pluck('name'))->toContain('legacy.thing');
});
