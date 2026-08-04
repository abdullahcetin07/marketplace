<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;
use App\Shared\Enums\UserType;

/**
 * Registration inputs.
 *
 * Locale preferences arrive as ISO CODES, not ids. The client has no business
 * knowing internal primary keys, and codes survive a database reseed —
 *
 * @see docs/001_Architecture.md §8. RegisterUserAction resolves them to ids.
 */
final class RegisterUserDTO extends BaseDTO
{
    public function __construct(
        public readonly string $firstName,
        /** Nullable by decision (ADR-012) — sole traders may have no surname. */
        public readonly ?string $lastName,
        public readonly string $email,
        public readonly string $password,
        public readonly UserType $type,
        public readonly ?string $phone = null,
        public readonly ?string $languageCode = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $currencyCode = null,
        public readonly bool $acceptedTerms = false,
    ) {}

    public function normalisedEmail(): string
    {
        return mb_strtolower(trim($this->email));
    }

    /**
     * Computed the same way `User::displayName()` computes it — never stored.
     */
    public function displayName(): string
    {
        return trim($this->firstName.' '.($this->lastName ?? ''));
    }

    /**
     * The config key of the role this actor type receives on registration.
     * Never a role id, never a hard-coded role name.
     */
    public function roleKey(): string
    {
        return match ($this->type) {
            UserType::Seller => 'seller',
            UserType::Customer => 'customer',
            // Unreachable: RegisterUserAction refuses admin registration
            // outright. Present so the match stays exhaustive.
            UserType::Admin => 'admin',
        };
    }
}
