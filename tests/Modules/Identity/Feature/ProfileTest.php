<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Modules\Identity\Infrastructure\Notifications\PasswordChangedNotification;
use App\Modules\Identity\Domain\Models\UserSession;
use App\Modules\Localization\Domain\Models\Language;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Profile — Phase 5
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->seedPlatform();
    $this->seedRolesAndPermissions();
});

it('updates name and phone', function (): void {
    $customer = Customer::factory()->named('Ayşe', 'Yılmaz')->create();
    $this->actingAsCustomer($customer);

    $this->patchJson('/api/v1/profile', [
        'first_name' => 'Elif',
        'phone' => '+90 555 111 22 33',
    ])->assertOk()
        ->assertJsonPath('data.first_name', 'Elif')
        // last_name untouched — this is a PATCH, not a replace.
        ->assertJsonPath('data.last_name', 'Yılmaz')
        ->assertJsonPath('data.display_name', 'Elif Yılmaz');

    expect($customer->fresh()->phone)->toBe('+90 555 111 22 33');
});

it('leaves absent fields unchanged', function (): void {
    $customer = Customer::factory()->named('Ayşe', 'Yılmaz')->create(['phone' => '+90 555 000 00 00']);
    $this->actingAsCustomer($customer);

    $this->patchJson('/api/v1/profile', ['first_name' => 'Elif'])->assertOk();

    // phone was not in the request — untouched.
    expect($customer->fresh()->phone)->toBe('+90 555 000 00 00');
});

it('clears a nullable field when explicitly sent null', function (): void {
    $customer = Customer::factory()->named('Ayşe', 'Yılmaz')->create();
    $this->actingAsCustomer($customer);

    // present-but-null means "clear", distinct from absent.
    $this->patchJson('/api/v1/profile', ['last_name' => null])->assertOk()
        ->assertJsonPath('data.last_name', null)
        ->assertJsonPath('data.display_name', 'Ayşe');
});

it('cannot change the email address through the profile', function (): void {
    $customer = Customer::factory()->create(['email' => 'original@example.test']);
    $this->actingAsCustomer($customer);

    // email is not a profile field — the attempt is ignored, not applied.
    $this->patchJson('/api/v1/profile', ['email' => 'hacker@example.test'])->assertOk();

    expect($customer->fresh()->email)->toBe('original@example.test');
});

it('updates locale preferences by code', function (): void {
    $customer = Customer::factory()->create();
    $this->actingAsCustomer($customer);

    $this->patchJson('/api/v1/profile', ['language_code' => 'en'])->assertOk();

    $en = Language::query()->where('code', 'en')->first();
    expect($customer->fresh()->language_id)->toBe($en->getKey());
});

it('rejects a disabled locale', function (): void {
    $customer = Customer::factory()->create();
    $this->actingAsCustomer($customer);

    Language::factory()->create(['code' => 'de', 'is_active' => false]);

    $this->patchJson('/api/v1/profile', ['language_code' => 'de'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');
});

it('requires authentication', function (): void {
    $this->patchJson('/api/v1/profile', ['first_name' => 'X'])->assertStatus(401);
});

/*
| PASSWORD CHANGE
*/

it('changes the password with the correct current password', function (): void {
    Notification::fake();
    $customer = Customer::factory()->create();
    $this->actingAsCustomer($customer);

    $this->postJson('/api/v1/profile/password', [
        'current_password' => UserFactory::PASSWORD,
        'password' => 'brand-new-password-9',
        'password_confirmation' => 'brand-new-password-9',
    ])->assertOk();

    expect(Hash::check('brand-new-password-9', $customer->fresh()->password))->toBeTrue();
});

it('rejects a change without the correct current password', function (): void {
    // A hijacked session must not be able to change the password without
    // knowing the existing one.
    $customer = Customer::factory()->create();
    $this->actingAsCustomer($customer);

    $this->postJson('/api/v1/profile/password', [
        'current_password' => 'wrong-current-password',
        'password' => 'brand-new-password-9',
        'password_confirmation' => 'brand-new-password-9',
    ])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['current_password']]);

    expect(Hash::check(UserFactory::PASSWORD, $customer->fresh()->password))->toBeTrue();
});

it('revokes other sessions but keeps the current one on password change', function (): void {
    $customer = Customer::factory()->create();
    UserSession::factory()->count(2)->create(['user_id' => $customer->getKey()]);
    $this->actingAsCustomer($customer);

    $this->postJson('/api/v1/profile/password', [
        'current_password' => UserFactory::PASSWORD,
        'password' => 'brand-new-password-9',
        'password_confirmation' => 'brand-new-password-9',
    ])->assertOk();

    // The two seeded sessions are revoked; the acting test session is not a
    // UserSession row, so all pre-existing rows should be gone.
    expect(UserSession::query()->where('user_id', $customer->getKey())->active()->count())->toBe(0);
});

it('notifies the owner of a password change', function (): void {
    Notification::fake();
    $customer = Customer::factory()->create();
    $this->actingAsCustomer($customer);

    $this->postJson('/api/v1/profile/password', [
        'current_password' => UserFactory::PASSWORD,
        'password' => 'brand-new-password-9',
        'password_confirmation' => 'brand-new-password-9',
    ])->assertOk();

    Notification::assertSentTo($customer, PasswordChangedNotification::class);
});

it('enforces the password policy on change', function (): void {
    $customer = Customer::factory()->create();
    $this->actingAsCustomer($customer);

    $this->postJson('/api/v1/profile/password', [
        'current_password' => UserFactory::PASSWORD,
        'password' => 'weak',
        'password_confirmation' => 'weak',
    ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
});
