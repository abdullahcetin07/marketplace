<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Modules\Identity\Domain\Events\EmailVerified;
use App\Modules\Identity\Infrastructure\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| Email verification — ADR-025
|--------------------------------------------------------------------------
|
| The link is signed and emailed; the credential never appears in a response.
| A tampered or expired link is rejected before the account is touched.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * Build the signed API callback URL the frontend would receive, so tests can
 * call it directly. Mirrors what VerifyEmailNotification composes.
 */
function verificationUrl(Customer $user, ?string $hash = null): string
{
    return URL::temporarySignedRoute(
        'api.v1.auth.email.verify',
        now()->addMinutes(60),
        [
            'uuid' => $user->uuid,
            'hash' => $hash ?? sha1((string) $user->getEmailForVerification()),
        ],
    );
}

it('verifies an email through a valid signed link', function (): void {
    $user = Customer::factory()->unverified()->create();

    expect($user->hasVerifiedEmail())->toBeFalse();

    $this->postJson(verificationUrl($user))->assertOk();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects a link with a tampered signature', function (): void {
    $user = Customer::factory()->unverified()->create();

    $url = verificationUrl($user).'x'; // corrupt the signature

    // 403 from hasValidSignature — the account is never touched.
    $this->postJson($url)->assertStatus(403);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects a link whose uuid was swapped', function (): void {
    $target = Customer::factory()->unverified()->create();
    $other = Customer::factory()->unverified()->create();

    // Take a valid signature for `target`, point it at `other`'s uuid.
    $signed = verificationUrl($target);
    $tampered = str_replace($target->uuid, $other->uuid, $signed);

    $this->postJson($tampered)->assertStatus(403);

    expect($other->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($target->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects an expired link', function (): void {
    $user = Customer::factory()->unverified()->create();

    $url = URL::temporarySignedRoute(
        'api.v1.auth.email.verify',
        now()->subMinute(), // already expired
        ['uuid' => $user->uuid, 'hash' => sha1((string) $user->getEmailForVerification())],
    );

    $this->postJson($url)->assertStatus(403);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects a link whose hash does not match the account', function (): void {
    $user = Customer::factory()->unverified()->create();

    // Correctly signed, but the hash is for a different email. The signature
    // passes; the action's hash check does not.
    $url = verificationUrl($user, hash: sha1('someone-else@example.test'));

    $this->postJson($url)
        ->assertStatus(422)
        ->assertJsonPath('code', 'EMAIL_VERIFICATION_INVALID');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('is idempotent on a repeat click', function (): void {
    $user = Customer::factory()->unverified()->create();
    $url = verificationUrl($user);

    $this->postJson($url)->assertOk();
    // Second click — a double-click, a prefetch, a forwarded email.
    $this->postJson($url)->assertOk();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('fires EmailVerified only on the first verification', function (): void {
    Event::fake([EmailVerified::class]);

    $user = Customer::factory()->unverified()->create();
    $url = verificationUrl($user);

    $this->postJson($url)->assertOk();
    $this->postJson($url)->assertOk();

    // Once, not twice — a re-verification must not duplicate timeline entries.
    Event::assertDispatchedTimes(EmailVerified::class, 1);
});

it('never returns a token or signature in the response', function (): void {
    $user = Customer::factory()->unverified()->create();

    $response = $this->postJson(verificationUrl($user));

    $body = json_encode($response->json());
    expect($body)->not->toContain('signature')
        ->and($body)->not->toContain('token');
});

/*
| RESEND — non-disclosing, like forgot-password (ADR-025)
*/

it('resends verification to an unverified account', function (): void {
    Notification::fake();
    $user = Customer::factory()->unverified()->create(['email' => 'buyer@example.test']);

    $this->postJson('/api/v1/auth/email/resend', [
        'email' => 'buyer@example.test', 'type' => 'customer',
    ])->assertOk();

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('sends nothing for an already-verified account', function (): void {
    Notification::fake();
    Customer::factory()->create(['email' => 'verified@example.test']); // verified by default

    $this->postJson('/api/v1/auth/email/resend', [
        'email' => 'verified@example.test', 'type' => 'customer',
    ])->assertOk();

    Notification::assertNothingSent();
});

it('sends nothing for an unknown address', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/email/resend', [
        'email' => 'ghost@example.test', 'type' => 'customer',
    ])->assertOk();

    Notification::assertNothingSent();
});

it('answers resend identically whether the account exists or not', function (): void {
    Customer::factory()->unverified()->create(['email' => 'real@example.test']);

    $known = $this->postJson('/api/v1/auth/email/resend', [
        'email' => 'real@example.test', 'type' => 'customer',
    ]);
    $unknown = $this->postJson('/api/v1/auth/email/resend', [
        'email' => 'ghost@example.test', 'type' => 'customer',
    ]);

    expect($known->json())->toBe($unknown->json());
});

it('does not reveal existence through validation on resend', function (): void {
    $this->postJson('/api/v1/auth/email/resend', [
        'email' => 'ghost@example.test', 'type' => 'customer',
    ])->assertOk();
});

it('sends a verification email on registration', function (): void {
    Notification::fake();
    $this->seedRolesAndPermissions();

    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Ayşe',
        'email' => 'fresh@example.test',
        'password' => 'correct-horse-battery-staple-9',
        'password_confirmation' => 'correct-horse-battery-staple-9',
        'type' => 'customer',
        'accepted_terms' => true,
    ])->assertCreated()
        ->assertJsonPath('data.requires_verification', true);

    $user = Customer::query()->where('email', 'fresh@example.test')->first();
    Notification::assertSentTo($user, VerifyEmailNotification::class);
});
