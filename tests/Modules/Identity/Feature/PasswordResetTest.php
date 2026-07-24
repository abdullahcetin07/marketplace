<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Customer;
use App\Modules\Identity\Domain\Models\UserSession;
use App\Modules\Identity\Infrastructure\Notifications\PasswordChangedNotification;
use App\Modules\Identity\Infrastructure\Notifications\ResetPasswordNotification;
use App\Shared\Enums\Status;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
|--------------------------------------------------------------------------
| Password reset — ADR-025
|--------------------------------------------------------------------------
|
| The properties here are security properties. A failure is a vulnerability,
| not a test failure — read the assertion before "fixing" it.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
    Notification::fake();
});

const NEW_PASSWORD = 'correct-horse-battery-staple-9';

/*
| THE CENTRAL GUARANTEE (ADR-025): no token ever leaves through the API.
*/

it('never returns a token in the response', function (): void {
    Customer::factory()->create(['email' => 'buyer@example.test']);

    $response = $this->postJson('/api/v1/auth/password/forgot', [
        'email' => 'buyer@example.test',
        'type' => 'customer',
    ]);

    $response->assertOk();

    // Not under any key, at any depth.
    expect(json_encode($response->json()))->not->toContain('token');
});

it('answers identically for known and unknown addresses', function (): void {
    Customer::factory()->create(['email' => 'real@example.test']);

    $known = $this->postJson('/api/v1/auth/password/forgot', [
        'email' => 'real@example.test', 'type' => 'customer',
    ]);
    $unknown = $this->postJson('/api/v1/auth/password/forgot', [
        'email' => 'ghost@example.test', 'type' => 'customer',
    ]);

    // Byte for byte. Anything less is an existence oracle.
    expect($known->json())->toBe($unknown->json())
        ->and($known->status())->toBe($unknown->status());
});

it('answers identically for a suspended account', function (): void {
    Customer::factory()->create([
        'email' => 'suspended@example.test',
        'status' => Status::Suspended,
    ]);

    $suspended = $this->postJson('/api/v1/auth/password/forgot', [
        'email' => 'suspended@example.test', 'type' => 'customer',
    ]);
    $unknown = $this->postJson('/api/v1/auth/password/forgot', [
        'email' => 'ghost@example.test', 'type' => 'customer',
    ]);

    expect($suspended->json())->toBe($unknown->json());
});

it('does not issue a token to a suspended account', function (): void {
    // Resetting into a suspended account would hand an attacker a working
    // credential on an account the platform has deliberately disabled.
    Customer::factory()->create([
        'email' => 'suspended@example.test',
        'status' => Status::Suspended,
    ]);

    $this->postJson('/api/v1/auth/password/forgot', [
        'email' => 'suspended@example.test', 'type' => 'customer',
    ]);

    Notification::assertNothingSent();
});

it('emails the token to a real account', function (): void {
    $customer = Customer::factory()->create(['email' => 'buyer@example.test']);

    $this->postJson('/api/v1/auth/password/forgot', [
        'email' => 'buyer@example.test', 'type' => 'customer',
    ]);

    // Out-of-band delivery is the entire point.
    Notification::assertSentTo($customer, ResetPasswordNotification::class);
});

it('sends nothing for an unknown address', function (): void {
    $this->postJson('/api/v1/auth/password/forgot', [
        'email' => 'ghost@example.test', 'type' => 'customer',
    ]);

    Notification::assertNothingSent();
});

it('does not reveal existence through validation', function (): void {
    // No `exists` rule — an unknown address must validate cleanly.
    $this->postJson('/api/v1/auth/password/forgot', [
        'email' => 'ghost@example.test', 'type' => 'customer',
    ])->assertOk();
});

/*
| TOKEN LIFECYCLE
*/

it('accepts a valid token and sets the new password', function (): void {
    $customer = Customer::factory()->create(['email' => 'buyer@example.test']);
    $token = Password::broker('customers')->createToken($customer);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'buyer@example.test',
        'token' => $token,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
        'type' => 'customer',
    ])->assertOk();

    expect(Hash::check(NEW_PASSWORD, $customer->fresh()->password))->toBeTrue();
});

it('makes a token single-use', function (): void {
    $customer = Customer::factory()->create(['email' => 'buyer@example.test']);
    $token = Password::broker('customers')->createToken($customer);

    $payload = [
        'email' => 'buyer@example.test',
        'token' => $token,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
        'type' => 'customer',
    ];

    $this->postJson('/api/v1/auth/password/reset', $payload)->assertOk();

    // Second use must fail — the token is deleted on success.
    $this->postJson('/api/v1/auth/password/reset', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'RESET_TOKEN_INVALID');
});

it('invalidates previous tokens when a new one is issued', function (): void {
    $customer = Customer::factory()->create(['email' => 'buyer@example.test']);

    $first = Password::broker('customers')->createToken($customer);
    $second = Password::broker('customers')->createToken($customer);

    expect($first)->not->toBe($second);

    // Three "forgot password" clicks must leave one live credential, not three.
    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'buyer@example.test',
        'token' => $first,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
        'type' => 'customer',
    ])->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_INVALID');
});

it('gives one indistinguishable reason for every token failure', function (): void {
    Customer::factory()->create(['email' => 'buyer@example.test']);

    // Garbage token against a real address.
    $bad = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'buyer@example.test', 'token' => 'nonsense',
        'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD,
        'type' => 'customer',
    ]);

    // Garbage token against an unknown address.
    $ghost = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'ghost@example.test', 'token' => 'nonsense',
        'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD,
        'type' => 'customer',
    ]);

    expect($bad->json())->toBe($ghost->json());
});

it('will not redeem a token across actor types', function (): void {
    // Uniqueness is (type, email), so the same address may exist twice. A
    // customer token must not open the admin account.
    Customer::factory()->create(['email' => 'both@example.test']);
    Admin::factory()->create(['email' => 'both@example.test']);

    $customerToken = Password::broker('customers')
        ->createToken(Customer::query()->where('email', 'both@example.test')->first());

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'both@example.test',
        'token' => $customerToken,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
        'type' => 'admin',
    ])->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_INVALID');
});

/*
| POST-RESET CASCADE
*/

it('revokes every session, keeping none', function (): void {
    $customer = Customer::factory()->create(['email' => 'buyer@example.test']);
    UserSession::factory()->count(3)->create(['user_id' => $customer->getKey()]);

    $token = Password::broker('customers')->createToken($customer);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'buyer@example.test', 'token' => $token,
        'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD,
        'type' => 'customer',
    ])->assertOk();

    // Unlike a voluntary change, NO session survives — the user reset because
    // they lost control of the account.
    expect(UserSession::query()->where('user_id', $customer->getKey())->active()->count())->toBe(0);
});

it('clears the remember token', function (): void {
    $customer = Customer::factory()->create([
        'email' => 'buyer@example.test',
        'remember_token' => 'still-valid-cookie',
    ]);

    $token = Password::broker('customers')->createToken($customer);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'buyer@example.test', 'token' => $token,
        'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD,
        'type' => 'customer',
    ])->assertOk();

    // A cookie issued under the old password would otherwise survive.
    expect($customer->fresh()->remember_token)->toBeNull();
});

it('stamps password_changed_at', function (): void {
    $customer = Customer::factory()->create(['email' => 'buyer@example.test']);
    $token = Password::broker('customers')->createToken($customer);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'buyer@example.test', 'token' => $token,
        'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD,
        'type' => 'customer',
    ]);

    expect($customer->fresh()->password_changed_at)->not->toBeNull();
});

it('notifies the owner that the password changed', function (): void {
    $customer = Customer::factory()->create(['email' => 'buyer@example.test']);
    $token = Password::broker('customers')->createToken($customer);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'buyer@example.test', 'token' => $token,
        'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD,
        'type' => 'customer',
    ]);

    // The unexpected case is the point: a user who did not do this has just
    // been told their account is compromised.
    Notification::assertSentTo($customer, PasswordChangedNotification::class);
});

it('returns no session after a reset', function (): void {
    $customer = Customer::factory()->create(['email' => 'buyer@example.test']);
    $token = Password::broker('customers')->createToken($customer);

    $response = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'buyer@example.test', 'token' => $token,
        'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD,
        'type' => 'customer',
    ]);

    // Signing in proves possession rather than assuming it.
    expect($response->json('data'))->toBeNull();
});

it('enforces the password policy on reset', function (): void {
    $customer = Customer::factory()->create(['email' => 'buyer@example.test']);
    $token = Password::broker('customers')->createToken($customer);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'buyer@example.test', 'token' => $token,
        'password' => 'short', 'password_confirmation' => 'short',
        'type' => 'customer',
    ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
});
