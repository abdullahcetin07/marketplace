<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Modules\Identity\Application\Services\TwoFactorService;
use App\Core\Domain\Contracts\OtpStoreContract;
use App\Modules\Identity\Infrastructure\Notifications\EmailOtpNotification;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

/*
|--------------------------------------------------------------------------
| Two-factor authentication — Phase 6
|--------------------------------------------------------------------------
|
| TOTP (primary) → recovery codes → email OTP (fallback), per Q5.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
    $this->seedRolesAndPermissions();
    $this->google2fa = new Google2FA;
});

/** A valid TOTP code for a secret, so tests can act as the authenticator. */
function totp(Google2FA $g, string $secret): string
{
    return $g->getCurrentOtp($secret);
}

/*
| ENROLMENT — two steps, protected only after confirm
*/

it('starts enrolment with a secret and provisioning uri', function (): void {
    $customer = Customer::factory()->create();
    $this->actingAsCustomer($customer);

    $this->postJson('/api/v1/two-factor/enable')
        ->assertOk()
        ->assertJsonStructure(['data' => ['secret', 'provisioning_uri']]);

    // Persisted UNCONFIRMED — not yet protecting the account.
    expect($customer->fresh()->two_factor_secret)->not->toBeNull()
        ->and($customer->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('confirms enrolment with a valid code and returns recovery codes once', function (): void {
    $customer = Customer::factory()->create();
    $this->actingAsCustomer($customer);

    $this->postJson('/api/v1/two-factor/enable');
    $secret = $customer->fresh()->two_factor_secret;

    $response = $this->postJson('/api/v1/two-factor/confirm', [
        'code' => totp($this->google2fa, $secret),
    ])->assertOk()
        ->assertJsonStructure(['data' => ['recovery_codes']]);

    expect($customer->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($response->json('data.recovery_codes'))->toHaveCount(8);
});

it('rejects confirmation with a wrong code', function (): void {
    $customer = Customer::factory()->create();
    $this->actingAsCustomer($customer);
    $this->postJson('/api/v1/two-factor/enable');

    $this->postJson('/api/v1/two-factor/confirm', ['code' => '000000'])
        ->assertStatus(401)
        // A wrong code during enrolment is DISCLOSABLE (AuthenticationFailed):
        // the owner is already authenticated, so telling them the 2FA code was
        // wrong leaks nothing and is more useful than a generic credential error.
        ->assertJsonPath('code', 'TWO_FACTOR_INVALID');

    expect($customer->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('refuses to re-enable while already enabled', function (): void {
    $customer = Customer::factory()->withTwoFactor()->create();
    $this->actingAsCustomer($customer);

    // Re-enabling would silently unconfirm a live setup.
    $this->postJson('/api/v1/two-factor/enable')
        ->assertStatus(409)
        ->assertJsonPath('code', 'TWO_FACTOR_ALREADY_ENABLED');
});

/*
| RECOVERY CODES — hashed at rest, single-use
*/

it('stores recovery codes hashed, never in plaintext', function (): void {
    $customer = Customer::factory()->create();
    $this->actingAsCustomer($customer);
    $this->postJson('/api/v1/two-factor/enable');
    $secret = $customer->fresh()->two_factor_secret;

    $codes = $this->postJson('/api/v1/two-factor/confirm', [
        'code' => totp($this->google2fa, $secret),
    ])->json('data.recovery_codes');

    $stored = $customer->fresh()->getAttributes()['two_factor_recovery_codes'];

    // A plaintext code must not appear in the stored blob.
    expect($stored)->not->toContain($codes[0]);
});

it('consumes a recovery code on use', function (): void {
    $customer = Customer::factory()->create();
    $this->actingAsCustomer($customer);
    $this->postJson('/api/v1/two-factor/enable');
    $secret = $customer->fresh()->two_factor_secret;
    $codes = $this->postJson('/api/v1/two-factor/confirm', [
        'code' => totp($this->google2fa, $secret),
    ])->json('data.recovery_codes');

    $service = app(TwoFactorService::class);
    $user = $customer->fresh();

    expect($service->verify($user, $codes[0]))->toBeTrue()
        // Single-use — the same code fails the second time.
        ->and($service->verify($user->fresh(), $codes[0]))->toBeFalse();
});

it('regenerates recovery codes with the current password', function (): void {
    $customer = Customer::factory()->withTwoFactor()->create();
    $this->actingAsCustomer($customer);

    $this->postJson('/api/v1/two-factor/recovery-codes', [
        'current_password' => UserFactory::PASSWORD,
    ])->assertOk()
        ->assertJsonStructure(['data' => ['recovery_codes']]);
});

it('will not regenerate recovery codes without the password', function (): void {
    $customer = Customer::factory()->withTwoFactor()->create();
    $this->actingAsCustomer($customer);

    $this->postJson('/api/v1/two-factor/recovery-codes', [
        'current_password' => 'wrong',
    ])->assertStatus(422);
});

/*
| DISABLE — requires the password
*/

it('disables with the current password', function (): void {
    $customer = Customer::factory()->withTwoFactor()->create();
    $this->actingAsCustomer($customer);

    $this->deleteJson('/api/v1/two-factor', [
        'current_password' => UserFactory::PASSWORD,
    ])->assertOk();

    expect($customer->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('will not disable without the password', function (): void {
    // Turning off a factor is what an attacker does — it must re-prove the
    // password.
    $customer = Customer::factory()->withTwoFactor()->create();
    $this->actingAsCustomer($customer);

    $this->deleteJson('/api/v1/two-factor', [
        'current_password' => 'wrong',
    ])->assertStatus(422);

    expect($customer->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

/*
| EMAIL OTP FALLBACK (Q5)
*/

it('emails an OTP to an account with 2FA enabled', function (): void {
    Notification::fake();
    $customer = Customer::factory()->withTwoFactor()->create(['email' => 'buyer@example.test']);

    $this->postJson('/api/v1/two-factor/email-otp', [
        'email' => 'buyer@example.test',
        'password' => UserFactory::PASSWORD,
        'type' => 'customer',
    ])->assertOk();

    Notification::assertSentTo($customer, EmailOtpNotification::class);
});

it('never returns the OTP in the response', function (): void {
    Notification::fake();
    $customer = Customer::factory()->withTwoFactor()->create(['email' => 'buyer@example.test']);

    $response = $this->postJson('/api/v1/two-factor/email-otp', [
        'email' => 'buyer@example.test',
        'password' => UserFactory::PASSWORD,
        'type' => 'customer',
    ]);

    // ADR-025 — the code is a login credential; it leaves by email only.
    expect($response->json('data'))->toBeNull();
});

it('sends nothing when 2FA is not enabled', function (): void {
    Notification::fake();
    // Correct password, but no 2FA — email OTP is a fallback, not a way to add
    // a factor that was never set up.
    Customer::factory()->create(['email' => 'no2fa@example.test']);

    $this->postJson('/api/v1/two-factor/email-otp', [
        'email' => 'no2fa@example.test',
        'password' => UserFactory::PASSWORD,
        'type' => 'customer',
    ])->assertOk();

    Notification::assertNothingSent();
});

it('answers email-otp identically for unknown and wrong-password', function (): void {
    Customer::factory()->withTwoFactor()->create(['email' => 'real@example.test']);

    $unknown = $this->postJson('/api/v1/two-factor/email-otp', [
        'email' => 'ghost@example.test', 'password' => 'x', 'type' => 'customer',
    ]);
    $wrongPassword = $this->postJson('/api/v1/two-factor/email-otp', [
        'email' => 'real@example.test', 'password' => 'wrong', 'type' => 'customer',
    ]);

    expect($unknown->json())->toBe($wrongPassword->json());
});

it('verifies a stored email OTP as a valid second factor', function (): void {
    $customer = Customer::factory()->withTwoFactor()->create();

    $code = app(TwoFactorService::class)->issueEmailOtp($customer);

    // The fallback code passes verify(), ranked below TOTP and recovery codes.
    expect(app(TwoFactorService::class)->verify($customer, $code))->toBeTrue()
        // Single-use.
        ->and(app(OtpStoreContract::class)->has('email_otp:'.$customer->getKey()))->toBeFalse();
});

it('requires authentication for enrolment endpoints', function (): void {
    $this->postJson('/api/v1/two-factor/enable')->assertStatus(401);
    $this->getJson('/api/v1/two-factor')->assertStatus(401);
    $this->deleteJson('/api/v1/two-factor')->assertStatus(401);
});

it('reports status', function (): void {
    $customer = Customer::factory()->withTwoFactor()->create();
    $this->actingAsCustomer($customer);

    $this->getJson('/api/v1/two-factor')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.confirmed', true);
});
