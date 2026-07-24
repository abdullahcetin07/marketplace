<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;
use App\Shared\Enums\UserType;

/**
 * A request for an email-OTP fallback, made mid-login.
 *
 * Carries the same credentials as a login (email + password + type), because
 * it happens BETWEEN the two legs of a 2FA login: the first leg proved the
 * password and returned TWO_FACTOR_REQUIRED, and this re-proves it before
 * emailing a code. Re-proving is what stops this from being an oracle — it
 * leaks no more than the login it accompanies.
 */
final class TwoFactorChallengeDTO extends BaseDTO
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly UserType $type,
    ) {}

    public function normalisedEmail(): string
    {
        return mb_strtolower(trim($this->email));
    }
}
