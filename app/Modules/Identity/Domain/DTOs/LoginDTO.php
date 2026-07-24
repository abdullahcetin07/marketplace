<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;
use App\Shared\Enums\UserType;

/**
 * A login attempt's inputs.
 *
 * Validated by the FormRequest that produces it — a DTO is a shape, not a
 * guarantee. @see App\Core\Presentation\Requests\BaseRequest
 */
final class LoginDTO extends BaseDTO
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly UserType $type,
        public readonly bool $remember = false,
        /** TOTP or recovery code, when the account has 2FA confirmed. */
        public readonly ?string $twoFactorCode = null,
        /** Whether to skip the 2FA challenge on this device in future. */
        public readonly bool $trustDevice = false,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
    ) {}

    /**
     * Normalised for lookup and for the login_attempts index, which aggregates
     * failures per address — "User@Example.com" must not dodge the count.
     */
    public function normalisedEmail(): string
    {
        return mb_strtolower(trim($this->email));
    }

    public function guard(): string
    {
        return $this->type->guard();
    }
}
