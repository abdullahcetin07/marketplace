<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A seller editing their organization's profile.
 *
 * Presentation glue over existing columns — name and trading name only. Status,
 * ownership, plan and limits are changed by their own dedicated actions, never
 * a free-form profile edit.
 */
final class UpdateOrganizationDTO extends BaseDTO
{
    /**
     * @param  array<int, string>  $present
     */
    public function __construct(
        public readonly ?string $legalName = null,
        public readonly ?string $displayName = null,
        public readonly array $present = [],
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }
}
