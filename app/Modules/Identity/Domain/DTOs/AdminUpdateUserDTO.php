<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * An administrator's edit to ANOTHER user's account.
 *
 * The profile fields mirror UpdateProfileDTO, plus two things self-service does
 * not have: `status` (an admin may suspend or reactivate an account) and
 * `reason` (why — written into the audit entry's metadata, per the Phase 8
 * ruling). Still no email: changing the identity key is a separate workflow, not
 * an inline admin edit.
 *
 * PATCH semantics: `present` distinguishes "not supplied" from "supplied as
 * null". @see UpdateProfileDTO.
 */
final class AdminUpdateUserDTO extends BaseDTO
{
    public function __construct(
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $phone = null,
        /** Status enum VALUE (active|suspended|…); resolved in the action. */
        public readonly ?string $status = null,
        public readonly ?string $languageCode = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $currencyCode = null,
        public readonly ?string $timezoneName = null,
        /** Why the change was made — recorded in the audit trail. */
        public readonly ?string $reason = null,
        /** @var array<int, string> */
        public readonly array $present = [],
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }
}
