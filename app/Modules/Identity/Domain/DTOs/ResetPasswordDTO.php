<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;
use App\Shared\Enums\UserType;

/**
 * Redemption of a reset token.
 *
 * The token alone is not a complete credential — it is verified against the
 * email, so both must be presented.
 */
final class ResetPasswordDTO extends BaseDTO
{
    public function __construct(
        public readonly string $email,
        public readonly string $token,
        public readonly string $password,
        public readonly UserType $type,
    ) {}

    public function normalisedEmail(): string
    {
        return mb_strtolower(trim($this->email));
    }

    public function broker(): string
    {
        return match ($this->type) {
            UserType::Admin => 'admins',
            UserType::Seller => 'sellers',
            UserType::Customer => 'customers',
        };
    }
}
