<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;
use App\Shared\Enums\UserType;

/**
 * A "resend my verification email" request.
 *
 * Carries email + type rather than a user, because a just-registered account is
 * not signed in (registration returns no session) and must be resolvable
 * without authentication — exactly like the forgot-password flow, and with the
 * same non-disclosure obligation.
 */
final class ResendVerificationDTO extends BaseDTO
{
    public function __construct(
        public readonly string $email,
        public readonly UserType $type,
    ) {}

    public function normalisedEmail(): string
    {
        return mb_strtolower(trim($this->email));
    }
}
