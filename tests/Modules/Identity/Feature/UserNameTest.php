<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Customer;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| User name structure — ADR-012
|--------------------------------------------------------------------------
|
| `first_name` required, `last_name` nullable, display name COMPUTED.
|
| The nullable surname is not an edge case to tolerate — it is the reason the
| decision was made. The platform serves sole traders and markets where a
| single given name is normal.
|
*/

it('computes the display name from both parts', function (): void {
    $user = Customer::factory()->named('Ayşe', 'Yılmaz')->create();

    expect($user->display_name)->toBe('Ayşe Yılmaz');
});

it('collapses to the given name when there is no surname', function (): void {
    $user = Customer::factory()->named('Madonna', null)->create();

    // No trailing space — this is what trim() is for.
    expect($user->display_name)->toBe('Madonna')
        ->and($user->last_name)->toBeNull();
});

it('persists a null surname rather than an empty string', function (): void {
    // '' and null are different: '' would render as "Madonna " with a trailing
    // space and would sort differently.
    $user = Customer::factory()->withoutLastName()->create();

    expect($user->fresh()->getAttributes()['last_name'])->toBeNull();
});

it('has no persisted full_name column', function (): void {
    // ADR-012 is explicit: the display name is computed, never stored. A
    // denormalised copy drifts the first time one side is written alone.
    expect(Schema::hasColumn('users', 'full_name'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'name'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'first_name'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'last_name'))->toBeTrue();
});

/*
| The four guarantees of a computed attribute (ADR-012):
| never persisted · never writable · never mass assignable · always computed.
*/

it('is never persisted as a column', function (): void {
    expect(Schema::hasColumn('users', 'display_name'))->toBeFalse();
});

it('is never writable', function (): void {
    $user = Customer::factory()->named('Ayşe', 'Yılmaz')->make();

    // Fails loudly at assignment. Silently doing nothing, or attempting an
    // INSERT against a column that does not exist, are both worse.
    expect(fn () => $user->display_name = 'Someone Else')
        ->toThrow(LogicException::class);
});

it('is never mass assignable', function (): void {
    expect((new Customer)->getFillable())->not->toContain('display_name');
});

it('is always computed, never cached from a stale write', function (): void {
    $user = Customer::factory()->named('Ayşe', 'Yılmaz')->create();

    $user->first_name = 'Elif';

    // Recomputed on read, before any save — it cannot disagree with its parts.
    expect($user->display_name)->toBe('Elif Yılmaz');
});

it('appears in array and JSON serialisation', function (): void {
    $user = Customer::factory()->named('Ayşe', 'Yılmaz')->make();

    expect($user->toArray())->toHaveKey('display_name')
        ->and($user->toArray()['display_name'])->toBe('Ayşe Yılmaz');
});

it('recomputes the display name when a part changes', function (): void {
    $user = Customer::factory()->named('Ayşe', 'Yılmaz')->create();

    $user->update(['last_name' => 'Demir']);

    expect($user->fresh()->display_name)->toBe('Ayşe Demir');
});

it('builds initials from both parts, or one', function (): void {
    expect(Customer::factory()->named('Ayşe', 'Yılmaz')->make()->initials())->toBe('AY')
        ->and(Customer::factory()->named('Madonna', null)->make()->initials())->toBe('M');
});

it('exposes both parts and the computed name through the API', function (): void {
    $this->seedPlatform();

    $customer = Customer::factory()->named('Ayşe', 'Yılmaz')->create([
        'email' => 'ayse@example.test',
    ]);

    $this->actingAsCustomer($customer);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Ayşe')
        ->assertJsonPath('data.last_name', 'Yılmaz')
        ->assertJsonPath('data.display_name', 'Ayşe Yılmaz')
        // The old single field is gone from the contract.
        ->assertJsonMissingPath('data.name');
});

it('registers a user with a surname', function (): void {
    $this->seedPlatform();
    $this->seedRolesAndPermissions();

    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Ayşe',
        'last_name' => 'Yılmaz',
        'email' => 'new@example.test',
        'password' => 'correct-horse-battery-staple-9',
        'password_confirmation' => 'correct-horse-battery-staple-9',
        'type' => 'customer',
        'accepted_terms' => true,
    ])->assertCreated()
        ->assertJsonPath('data.user.display_name', 'Ayşe Yılmaz');
});

it('registers a user without a surname', function (): void {
    $this->seedPlatform();
    $this->seedRolesAndPermissions();

    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Madonna',
        'email' => 'single@example.test',
        'password' => 'correct-horse-battery-staple-9',
        'password_confirmation' => 'correct-horse-battery-staple-9',
        'type' => 'customer',
        'accepted_terms' => true,
    ])->assertCreated()
        ->assertJsonPath('data.user.last_name', null)
        ->assertJsonPath('data.user.display_name', 'Madonna');
});

it('rejects registration with no first name', function (): void {
    $this->seedPlatform();

    $this->postJson('/api/v1/auth/register', [
        'last_name' => 'Yılmaz',
        'email' => 'nofirst@example.test',
        'password' => 'correct-horse-battery-staple-9',
        'password_confirmation' => 'correct-horse-battery-staple-9',
        'type' => 'customer',
        'accepted_terms' => true,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['first_name']]);
});

it('accepts a single-character given name', function (): void {
    // min:1, not min:2 — a one-letter given name is real.
    $this->seedPlatform();
    $this->seedRolesAndPermissions();

    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'X',
        'email' => 'x@example.test',
        'password' => 'correct-horse-battery-staple-9',
        'password_confirmation' => 'correct-horse-battery-staple-9',
        'type' => 'customer',
        'accepted_terms' => true,
    ])->assertCreated();
});

it('gives Filament the computed name', function (): void {
    $admin = Admin::factory()->named('Osman', 'Kaya')->create();

    expect($admin->getFilamentName())->toBe('Osman Kaya');
});
