<?php

declare(strict_types=1);

namespace App\Shared\Rules;

use App\Shared\Enums\UserType;
use Illuminate\Validation\Rules\Password;

/**
 * Password policy, in one place.
 *
 * **Three tiers, because the three actors carry different blast radii.** An
 * admin credential compromise is a platform-wide incident; a seller's is that
 * merchant's catalogue, prices and payout details; a shopper's is one person's
 * order history. A rule that treats them alike is either hostile to shoppers or
 * negligent about admins.
 *
 * Every tier checks the password against the Have I Been Pwned k-anonymity API,
 * which catches far more real-world compromise than any composition rule — a
 * long, mixed-case password that has already appeared in a breach is worse than
 * a short one that has not.
 *
 *     'password' => ['required', 'confirmed', StrongPassword::for($type)],
 *
 * @see docs/security.md
 */
final class StrongPassword
{
    /**
     * Rule appropriate to the actor type.
     */
    public static function for(UserType $type): Password
    {
        // Mirror default(): under the test suite, use the relaxed rule. The
        // strict tiers call uncompromised(), an HTTP call to Have I Been Pwned,
        // which the suite blocks with Http::preventStrayRequests() — so a
        // compliant fixture would turn every password test into a 500, and
        // mixedCase/min-14 would force each test to encode policy it is not
        // exercising. Production is unaffected: this branch is test-only.
        if (app()->runningUnitTests()) {
            return self::testing();
        }

        return self::rule($type);
    }

    /**
     * The routing itself, without the test-suite shortcut in `for()`.
     *
     * Separated so the mapping can be asserted at all: `for()` answers
     * `testing()` for every type while the suite is running, which would make a
     * test of "does a Seller get the seller rule" pass for the wrong reason.
     */
    public static function rule(UserType $type): Password
    {
        return match ($type) {
            UserType::Admin => self::staff(),
            UserType::Seller => self::seller(),
            UserType::Customer => self::customer(),
        };
    }

    /**
     * Admins: 14 characters, mixed case, digits, symbols, not breached.
     */
    public static function staff(): Password
    {
        return Password::min(14)
            ->mixedCase()
            ->numbers()
            ->symbols()
            ->uncompromised(1);
    }

    /**
     * Sellers: 12 characters, mixed case, digits, not breached.
     *
     * This was the shared seller-and-customer rule until 2026-08-24, and it
     * stays exactly as it was for the merchant side. A seller account holds a
     * catalogue, other people's prices and a payout destination; the friction
     * is proportionate there in a way it is not on a signup form.
     *
     * No symbol requirement — it measurably increases reset requests without
     * measurably increasing entropy once a length floor is in place.
     */
    public static function seller(): Password
    {
        return Password::min(12)
            ->mixedCase()
            ->numbers()
            ->uncompromised(3);
    }

    /**
     * Customers: 8 characters, at least one letter and one digit, not breached.
     *
     * **A deliberate relaxation (2026-08-24), not an oversight.** Twelve
     * characters with mixed case is a rule for an account that guards other
     * people's money; on a shopper's signup and password-reset form it buys
     * little and costs conversions and support tickets — the abandoned reset is
     * itself a security outcome, because the customer who gives up keeps using
     * whatever they had.
     *
     * `uncompromised(3)` is the part that survives, and it is the part that
     * works: it rejects "12345678" and "password1" by checking the breach
     * corpus rather than by guessing at shapes. `letters()` + `numbers()` only
     * rules out the degenerate "00000000" case.
     */
    public static function customer(): Password
    {
        return Password::min(8)
            ->letters()
            ->numbers()
            ->uncompromised(3);
    }

    /**
     * Relaxed rule for the test suite and factories. Never reachable from a
     * real request — callers must ask for it explicitly.
     */
    public static function testing(): Password
    {
        return Password::min(8);
    }

    /**
     * The default applied when no actor type is known yet (e.g. registration
     * before the type is decided). Errs on the stricter side.
     *
     * **It points at `seller()`, and that is what keeps this comment true.**
     * The default used to be `customer()` back when customer WAS the 12 +
     * mixed-case tier; leaving it there after the 2026-08-24 relaxation would
     * have quietly dropped every unknown-type caller — `Password::defaults()`
     * and a password change where the actor cannot be resolved — to the
     * shopper's floor. Nobody asked for that, and it would not have shown up in
     * any test of the change that was asked for.
     */
    public static function default(): Password
    {
        return app()->runningUnitTests() ? self::testing() : self::seller();
    }
}
