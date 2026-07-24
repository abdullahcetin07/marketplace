<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * Registering a new legal company.
 *
 * The owner is the authenticated seller creating it. Locale arrives as ISO
 * CODES (the client never handles internal ids); the plan is an optional slug,
 * null → the org runs on the system default store limit until an admin assigns
 * a plan.
 */
final class RegisterOrganizationDTO extends BaseDTO
{
    public function __construct(
        public readonly int $ownerId,
        public readonly string $legalName,
        public readonly ?string $displayName,
        public readonly string $slug,
        public readonly string $countryCode,
        public readonly string $currencyCode,
        public readonly ?string $planSlug = null,
    ) {}
}
