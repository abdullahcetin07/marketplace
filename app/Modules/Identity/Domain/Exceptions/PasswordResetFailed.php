<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;

/**
 * A reset token could not be redeemed.
 *
 * ONE REASON, DELIBERATELY. Expired, already used, wrong address, never
 * existed — all produce `RESET_TOKEN_INVALID`. Distinguishing them would tell
 * an attacker holding a guessed token whether the address is real, which is
 * the same oracle the forgot-password envelope is careful to avoid.
 *
 * Not reportable: a user clicking a day-old link is expected behaviour, not an
 * incident.
 */
final class PasswordResetFailed extends BaseException
{
    protected int $status = 422;

    public static function invalidToken(): self
    {
        $exception = new self;
        $exception->errorCode = 'reset_token_invalid';

        return $exception;
    }
}
