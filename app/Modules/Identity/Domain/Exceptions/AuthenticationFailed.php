<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;

/**
 * Every way a login can legitimately fail.
 *
 * ONE CLASS, MANY REASONS — deliberately.
 *
 * The `reason` is recorded in `login_attempts.failure_reason` for detection,
 * but the message shown to the client is IDENTICAL for
 * `invalid_credentials` and every other credential-shaped failure. Telling a
 * caller "that account is suspended" confirms the address exists, which turns
 * the login form into an account enumeration oracle.
 *
 * Only failures that the user must act on differently — an unverified email, a
 * missing 2FA code — reveal themselves, and only once the password has already
 * been proven correct.
 *
 * @see App\Modules\Identity\Application\Actions\LoginAction
 * @see docs/authentication.md
 */
final class AuthenticationFailed extends BaseException
{
    /**
     * Reasons safe to tell the client, because the password was already
     * verified and the user genuinely needs to do something different.
     *
     * @var array<int, string>
     */
    private const array DISCLOSABLE = [
        'unverified',
        'two_factor_required',
        'two_factor_invalid',
    ];

    protected int $status = 401;

    /**
     * Machine-readable reason, stored on the attempt row. Never sent to the
     * client unless it is in self::DISCLOSABLE.
     */
    private string $reason = 'invalid_credentials';

    public static function invalidCredentials(): self
    {
        return self::because('invalid_credentials');
    }

    /**
     * The password was correct but the account is not active. Presented to the
     * client as an ordinary credential failure.
     */
    public static function suspended(): self
    {
        return self::because('suspended');
    }

    public static function unverified(): self
    {
        return self::because('unverified')->withStatus(403);
    }

    public static function twoFactorRequired(): self
    {
        return self::because('two_factor_required')->withStatus(403);
    }

    public static function twoFactorInvalid(): self
    {
        return self::because('two_factor_invalid');
    }

    /**
     * Wrong guard for this actor type — a seller posting to the admin login.
     * Indistinguishable from bad credentials by design.
     */
    public static function wrongGuard(): self
    {
        return self::because('wrong_guard');
    }

    public static function because(string $reason): self
    {
        $exception = new self;
        $exception->reason = $reason;

        return $exception;
    }

    /**
     * The true reason, for the login_attempts row and the audit channel.
     * Distinct from getErrorCode(), which is what the client is told.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * Collapses every non-disclosable reason to the same key, so the response
     * body cannot be used to distinguish "no such account" from "suspended".
     *
     * `getErrorCode()` on the base class uppercases this into the wire code —
     * `INVALID_CREDENTIALS`, `TWO_FACTOR_REQUIRED` (005 §25). The enumeration
     * guarantee lives here, in one method, never in a controller.
     */
    public function translationKey(): string
    {
        return in_array($this->reason, self::DISCLOSABLE, true)
            ? $this->reason
            : 'invalid_credentials';
    }
}
