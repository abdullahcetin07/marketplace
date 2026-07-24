<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;
use App\Shared\Enums\UserType;

/**
 * A "forgot my password" request.
 *
 * Carries the actor type because reset tokens are broker-scoped: an admin token
 * must not be redeemable against a customer account with the same address
 * (uniqueness is `(type, email)`).
 */
final class PasswordResetRequestDTO extends BaseDTO
{
    public function __construct(
        public readonly string $email,
        public readonly UserType $type,
    ) {}

    public function normalisedEmail(): string
    {
        return mb_strtolower(trim($this->email));
    }

    /**
     * The password broker for this actor type. Each has its own token expiry —
     * 15 minutes for admins, 60 for everyone else (`config/auth.php`).
     */
    public function broker(): string
    {
        return match ($this->type) {
            UserType::Admin => 'admins',
            UserType::Seller => 'sellers',
            UserType::Customer => 'customers',
        };
    }
}
