<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A seller editing a storefront's public contact details (§2.6). PATCH via
 * `present`.
 */
final class UpdateStoreContactDTO extends BaseDTO
{
    /**
     * @param array<string, mixed>|null $address
     * @param array<string, mixed>|null $supportHours
     * @param array<int, string> $present
     */
    public function __construct(
        public readonly ?string $publicEmail = null,
        public readonly ?string $publicPhone = null,
        public readonly ?array $address = null,
        public readonly ?array $supportHours = null,
        public readonly array $present = [],
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }
}
