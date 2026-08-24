<?php

declare(strict_types=1);

use App\Shared\Enums\UserType;
use App\Shared\Rules\StrongPassword;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/*
|--------------------------------------------------------------------------
| Three tiers, three blast radii
|--------------------------------------------------------------------------
|
| The customer tier was relaxed on 2026-08-24 (12 + mixedCase → 8 + letters +
| numbers) because that rule guards one person's order history while costing
| every shopper a signup and a reset. The seller and admin tiers guard other
| people's money and did not move — so the assertions that matter most here are
| the ones proving they did not.
|
| `StrongPassword::for()` deliberately answers `testing()` while the suite runs
| (uncompromised() is a network call the suite forbids), so these tests exercise
| the tiers and `rule()` directly. Asserting through `for()` would pass for the
| wrong reason.
|
*/

function passwordPasses(Password $rule, string $password): bool
{
    return Validator::make(
        ['password' => $password],
        ['password' => $rule],
    )->passes();
}

/**
 * Have I Been Pwned, answering "this suffix is not in the corpus".
 *
 * Set per test rather than in a `beforeEach`: `Http::fake()` MERGES stubs and
 * the first matching one wins, so a blanket clean stub registered up front
 * would silently outrank the breached-password stub below and that test would
 * pass while proving nothing.
 */
function fakeCleanBreachCorpus(): void
{
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
}

it('lets a shopper use eight characters with a letter and a digit', function (): void {
    expect(passwordPasses(StrongPassword::customer(), 'parola12'))->toBeTrue();
});

it('still refuses a shopper password that is too short, all digits or all letters', function (): void {
    expect(passwordPasses(StrongPassword::customer(), 'kisa1'))->toBeFalse()
        ->and(passwordPasses(StrongPassword::customer(), '12345678'))->toBeFalse()
        ->and(passwordPasses(StrongPassword::customer(), 'parolaparola'))->toBeFalse();
});

it('asks Have I Been Pwned nothing about a shopper password', function (): void {
    /*
    | The owner dropped `uncompromised()` from the customer tier on 2026-08-24,
    | for the friction AND for the synchronous third-party call on every signup
    | and reset. Both halves are asserted here: nothing leaves the process, and
    | a password that IS in the breach corpus is now accepted.
    |
    | "sifre123" is the case that makes it concrete — eight characters, letters
    | and digits, so every remaining rule says yes and only the corpus said no.
    */
    fakeCleanBreachCorpus();

    expect(passwordPasses(StrongPassword::customer(), 'sifre123'))->toBeTrue();

    Http::assertNothingSent();
});

it('still asks about a seller password', function (): void {
    /*
    | The tiers now differ in KIND, not only in length — so this is the other
    | half of the previous test, and the one that fails if a future tidy-up
    | "unifies" the rules.
    */
    fakeCleanBreachCorpus();

    expect(passwordPasses(StrongPassword::seller(), 'ParolaGuclu12'))->toBeTrue();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'pwnedpasswords.com'));
});

it('keeps the seller tier where the customer tier used to be', function (): void {
    fakeCleanBreachCorpus();

    expect(passwordPasses(StrongPassword::seller(), 'parola12'))->toBeFalse()
        ->and(passwordPasses(StrongPassword::seller(), 'parolaparola1'))->toBeFalse()
        ->and(passwordPasses(StrongPassword::seller(), 'ParolaGuclu12'))->toBeTrue();
});

it('leaves the admin tier untouched', function (): void {
    fakeCleanBreachCorpus();

    expect(passwordPasses(StrongPassword::staff(), 'ParolaGuclu12'))->toBeFalse()
        ->and(passwordPasses(StrongPassword::staff(), 'ParolaCokGuclu12!'))->toBeTrue();
});

it('routes each actor type to its own tier', function (): void {
    fakeCleanBreachCorpus();

    /*
    | Behavioural, not identity-based: a shopper's eight characters are accepted
    | for a Customer and refused for the two account types that can move money.
    */
    expect(passwordPasses(StrongPassword::rule(UserType::Customer), 'parola12'))->toBeTrue()
        ->and(passwordPasses(StrongPassword::rule(UserType::Seller), 'parola12'))->toBeFalse()
        ->and(passwordPasses(StrongPassword::rule(UserType::Admin), 'parola12'))->toBeFalse();

    expect(passwordPasses(StrongPassword::rule(UserType::Seller), 'ParolaGuclu12'))->toBeTrue()
        ->and(passwordPasses(StrongPassword::rule(UserType::Admin), 'ParolaGuclu12'))->toBeFalse();
});
