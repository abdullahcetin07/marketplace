<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Seller;
use App\Modules\Identity\Domain\Models\LoginAttempt;
use App\Modules\Identity\Domain\Models\UserDevice;
use App\Modules\Identity\Domain\Models\UserSession;
use App\Modules\Identity\Domain\Events\UserLoggedIn;
use App\Shared\Enums\Status;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Login
|--------------------------------------------------------------------------
|
| The properties asserted here are security properties, not features. A
| failure in this file is a vulnerability — read the assertion before
| "fixing" it.
|
| @see App\Modules\Identity\Application\Actions\LoginAction
| @see docs/authentication.md
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

function loginPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'buyer@example.test',
        'password' => UserFactory::PASSWORD,
        'type' => 'customer',
    ], $overrides);
}

it('signs a customer in and creates a session', function (): void {
    Customer::factory()->create(['email' => 'buyer@example.test']);

    $response = $this->postJson('/api/v1/auth/login', loginPayload());

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', 'buyer@example.test')
        ->assertJsonStructure(['data' => ['user' => ['id'], 'session_id']]);

    expect(UserSession::query()->count())->toBe(1)
        ->and(UserDevice::query()->count())->toBe(1);
});

it('never exposes the internal id', function (): void {
    $customer = Customer::factory()->create(['email' => 'buyer@example.test']);

    $response = $this->postJson('/api/v1/auth/login', loginPayload());

    // The public identifier is the UUID. @see docs/001_Architecture.md §8
    $response->assertJsonPath('data.user.id', $customer->uuid);
    expect($response->json('data.user.id'))->not->toBe((string) $customer->getKey());
});

/*
| ACCOUNT ENUMERATION
|
| A missing account, a wrong password and a suspended account must be
| indistinguishable from outside. If any of these three start returning a
| different code or status, the login form has become an oracle that tells an
| attacker which addresses are worth attacking.
*/
it('returns an identical response for unknown, wrong-password and suspended accounts', function (): void {
    Customer::factory()->create(['email' => 'real@example.test']);
    Customer::factory()->create([
        'email' => 'suspended@example.test',
        'status' => Status::Suspended,
    ]);

    $unknown = $this->postJson('/api/v1/auth/login', loginPayload(['email' => 'nobody@example.test']));
    $wrongPassword = $this->postJson('/api/v1/auth/login', loginPayload([
        'email' => 'real@example.test',
        'password' => 'definitely-not-the-password',
    ]));
    $suspended = $this->postJson('/api/v1/auth/login', loginPayload(['email' => 'suspended@example.test']));

    foreach ([$unknown, $wrongPassword, $suspended] as $response) {
        $response->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    // Same body, byte for byte.
    expect($wrongPassword->json())->toBe($unknown->json())
        ->and($suspended->json())->toBe($unknown->json());
});

/*
| GUARD ISOLATION AT THE LOGIN BOUNDARY
|
| GuardIsolationTest proves the models cannot see each other. This proves the
| login endpoint honours that: a seller's correct credentials posted with
| type=customer must fail exactly like a non-existent account.
*/
it('refuses a seller signing in through the customer guard', function (): void {
    Seller::factory()->create(['email' => 'merchant@example.test']);

    $this->postJson('/api/v1/auth/login', loginPayload(['email' => 'merchant@example.test']))
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');

    expect(UserSession::query()->count())->toBe(0);
});

it('does not expose an admin login through the public API', function (): void {
    // `type` is constrained to seller|customer. Admins authenticate through
    // the Filament panel, not here.
    $this->postJson('/api/v1/auth/login', loginPayload(['type' => 'admin']))
        ->assertStatus(422);
});

/*
| ATTEMPT RECORDING
|
| Failures are the point. A table of successes tells you nothing; a run of
| failures against one address is how credential stuffing becomes visible.
*/
it('records both successful and failed attempts', function (): void {
    Customer::factory()->create(['email' => 'buyer@example.test']);

    $this->postJson('/api/v1/auth/login', loginPayload(['password' => 'wrong']));
    $this->postJson('/api/v1/auth/login', loginPayload());

    expect(LoginAttempt::query()->count())->toBe(2)
        ->and(LoginAttempt::query()->failed()->count())->toBe(1)
        ->and(LoginAttempt::query()->successful()->count())->toBe(1);
});

it('records an attempt against an address that does not exist', function (): void {
    // Enumeration attempts must leave a trace, which is why user_id is
    // nullable on this table.
    $this->postJson('/api/v1/auth/login', loginPayload(['email' => 'ghost@example.test']));

    $attempt = LoginAttempt::query()->first();

    expect($attempt)->not->toBeNull()
        ->and($attempt->user_id)->toBeNull()
        ->and($attempt->email)->toBe('ghost@example.test')
        ->and($attempt->successful)->toBeFalse();
});

it('never stores the attempted password', function (): void {
    $this->postJson('/api/v1/auth/login', loginPayload(['password' => 'hunter2-secret']));

    $row = LoginAttempt::query()->first()->getAttributes();

    expect(json_encode($row))->not->toContain('hunter2-secret');
});

it('normalises the email so failure counts cannot be dodged by casing', function (): void {
    $this->postJson('/api/v1/auth/login', loginPayload(['email' => 'Buyer@Example.Test']));

    expect(LoginAttempt::query()->first()->email)->toBe('buyer@example.test');
});

it('stores the true failure reason even though the client is told otherwise', function (): void {
    Customer::factory()->create([
        'email' => 'suspended@example.test',
        'status' => Status::Suspended,
    ]);

    $this->postJson('/api/v1/auth/login', loginPayload(['email' => 'suspended@example.test']))
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');

    // Detection needs the truth; the client does not get it.
    expect(LoginAttempt::query()->first()->failure_reason)->toBe('suspended');
});

/*
| SESSION AND DEVICE TRACKING
*/
it('reuses one device row across repeated sign-ins', function (): void {
    Customer::factory()->create(['email' => 'buyer@example.test']);

    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'])
        ->postJson('/api/v1/auth/login', loginPayload());
    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'])
        ->postJson('/api/v1/auth/login', loginPayload());

    // Same browser: one device, two sessions. Otherwise the security page is
    // fifty identical rows of noise.
    expect(UserDevice::query()->count())->toBe(1)
        ->and(UserSession::query()->count())->toBe(2);
});

it('stamps last login and increments the counter', function (): void {
    $customer = Customer::factory()->create(['email' => 'buyer@example.test']);

    expect($customer->login_count)->toBe(0);

    $this->postJson('/api/v1/auth/login', loginPayload());

    $customer->refresh();

    expect($customer->login_count)->toBe(1)
        ->and($customer->last_login_at)->not->toBeNull();
});

it('dispatches UserLoggedIn after a successful sign-in', function (): void {
    Event::fake([UserLoggedIn::class]);

    Customer::factory()->create(['email' => 'buyer@example.test']);

    $this->postJson('/api/v1/auth/login', loginPayload());

    Event::assertDispatched(UserLoggedIn::class);
});

it('rejects a malformed payload before touching the database', function (): void {
    $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    expect(LoginAttempt::query()->count())->toBe(0);
});
