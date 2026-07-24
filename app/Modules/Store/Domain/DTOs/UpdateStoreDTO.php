<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A seller editing a store's core identity fields.
 *
 * Name only for now. The `slug` is the public path handle (ADR-035) and changing
 * it re-points the public URL and must re-check global uniqueness — a dedicated
 * action, not a free-form profile edit — so it is deliberately absent here.
 * Status is changed by the lifecycle actions, never this. PATCH via `present`.
 */
final class UpdateStoreDTO extends BaseDTO
{
    /**
     * @param  array<int, string>  $present
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly array $present = [],
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }
}
