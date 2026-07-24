<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;

/**
 * A verification link could not be honoured.
 *
 * ONE REASON. Tampered signature, expired link, wrong hash, unknown user — all
 * `EMAIL_VERIFICATION_INVALID`. Distinguishing them would leak whether a guessed
 * UUID maps to a real account.
 *
 * Signature and expiry failures are caught earlier, by the request's
 * `hasValidSignature()` check, and surface as a 403 before this is reached.
 * This covers the hash and lookup failures that only the action can detect.
 *
 * Not reportable — a stale link is expected.
 */
final class EmailVerificationFailed extends BaseException
{
    protected int $status = 422;

    public static function invalidLink(): self
    {
        $exception = new self;
        $exception->errorCode = 'email_verification_invalid';

        return $exception;
    }
}
