<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;
use App\Modules\Organization\Domain\Enums\OrganizationRole;

/**
 * An offer of membership to an email address.
 *
 * The role may not be Owner — ownership arrives only by transfer (ADR-029);
 * that rule is enforced in the action. The email is the recipient; whether they
 * already have an account is irrelevant at invite time (ADR-031 — invitations
 * never create users, and acceptance is what requires an account).
 */
final class InviteMemberDTO extends BaseDTO
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $email,
        public readonly OrganizationRole $role,
        public readonly int $invitedBy,
    ) {}

    public function normalisedEmail(): string
    {
        return mb_strtolower(trim($this->email));
    }
}
