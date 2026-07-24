<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use App\Models\User;
use App\Shared\Enums\UserType;

/*
|--------------------------------------------------------------------------
| Guard Isolation
|--------------------------------------------------------------------------
|
| The single most important behaviour in the authentication design: three
| guards over one table, isolated by the global scope each subclass applies.
|
| If any test in this file fails, an actor of one type can be resolved through
| another type's guard — which is a privilege-escalation bug, not a test
| failure. Treat accordingly.
|
| @see docs/authentication.md
|
*/

it('scopes each model to its own actor type', function (): void {
    Admin::factory()->create(['email' => 'a@example.test']);
    Seller::factory()->create(['email' => 's@example.test']);
    Customer::factory()->create(['email' => 'c@example.test']);

    expect(Admin::query()->count())->toBe(1)
        ->and(Seller::query()->count())->toBe(1)
        ->and(Customer::query()->count())->toBe(1)
        // The unscoped base model sees all three.
        ->and(User::query()->count())->toBe(3);
});

it('cannot resolve a seller through the admin model', function (): void {
    $seller = Seller::factory()->create();

    expect(Admin::query()->find($seller->getKey()))->toBeNull()
        ->and(Seller::query()->find($seller->getKey()))->not->toBeNull();
});

it('stamps the actor type automatically on create', function (): void {
    expect(Admin::factory()->create()->type)->toBe(UserType::Admin)
        ->and(Seller::factory()->create()->type)->toBe(UserType::Seller)
        ->and(Customer::factory()->create()->type)->toBe(UserType::Customer);
});

it('reports a guard name matching the actor type', function (): void {
    expect(Admin::factory()->make()->guardName())->toBe('admin')
        ->and(Seller::factory()->make()->guardName())->toBe('seller')
        ->and(Customer::factory()->make()->guardName())->toBe('customer');
});

it('does not authenticate a seller session against the admin guard', function (): void {
    $seller = Seller::factory()->create();

    $this->actingAs($seller, 'seller');

    expect(auth()->guard('seller')->check())->toBeTrue()
        ->and(auth()->guard('admin')->check())->toBeFalse()
        ->and(auth()->guard('customer')->check())->toBeFalse();
});

it('allows the same email address across different actor types', function (): void {
    $email = 'same@example.test';

    Seller::factory()->create(['email' => $email]);
    Customer::factory()->create(['email' => $email]);

    expect(User::query()->where('email', $email)->count())->toBe(2);
});

it('rejects a duplicate email within one actor type', function (): void {
    $email = 'dup@example.test';

    Seller::factory()->create(['email' => $email]);

    expect(fn () => Seller::factory()->create(['email' => $email]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('grants panel access only to the matching panel', function (): void {
    expect(Admin::factory()->create()->canAccessPanelId('admin'))->toBeTrue()
        ->and(Admin::factory()->create()->canAccessPanelId('seller'))->toBeFalse()
        ->and(Seller::factory()->create()->canAccessPanelId('seller'))->toBeTrue()
        ->and(Seller::factory()->create()->canAccessPanelId('admin'))->toBeFalse()
        // Customers have no panel at all.
        ->and(Customer::factory()->create()->canAccessPanelId('seller'))->toBeFalse();
});

it('denies panel access to a suspended account', function (): void {
    $admin = Admin::factory()->suspended()->create();

    expect($admin->canAccessPanelId('admin'))->toBeFalse();
});
