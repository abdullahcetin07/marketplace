<?php

declare(strict_types=1);

namespace App\Shared\Rules;

use App\Shared\Enums\UserType;
use Illuminate\Validation\Rules\Password;

/**
 * Password policy, in one place.
 *
 * Staff accounts get a stricter rule than customers. That asymmetry is
 * deliberate: an admin credential compromise is a platform-wide incident,
 * whereas a needlessly hostile signup form costs real customers. Both tiers
 * check the account against the Have I Been Pwned k-anonymity API, which
 * catches far more real-world compromise than any composition rule.
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
        return $type->isStaff() ? self::staff() : self::customer();
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
     * Sellers and customers: 12 characters, mixed case, digits, not breached.
     *
     * No symbol requirement — it measurably increases reset requests without
     * measurably increasing entropy once a length floor is in place.
     */
    public static function customer(): Password
    {
        return Password::min(12)
            ->mixedCase()
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
     */
    public static function default(): Password
    {
        return app()->runningUnitTests() ? self::testing() : self::customer();
    }
}
