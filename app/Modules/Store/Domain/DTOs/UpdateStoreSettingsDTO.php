<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A seller editing a storefront's operational settings (§2.2).
 *
 * PATCH semantics: only fields listed in `present` are written, so a partial
 * update never clears an omitted field.
 */
final class UpdateStoreSettingsDTO extends BaseDTO
{
    /**
     * @param array<string, mixed>|null $metadata
     * @param array<int, string> $present
     */
    public function __construct(
        public readonly ?string $announcement = null,
        public readonly ?bool $orderNoteEnabled = null,
        public readonly ?string $weightUnit = null,
        public readonly ?string $dimensionUnit = null,
        public readonly ?array $metadata = null,
        public readonly array $present = [],
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }
}
