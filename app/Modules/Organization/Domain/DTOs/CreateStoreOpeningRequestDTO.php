<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A seller's request to open a store (ADR-028).
 *
 * Carries what the seller PROPOSES; nothing here creates a store. The slug's
 * final uniqueness is the Store module's concern at creation time — this is a
 * request, not the store.
 */
final class CreateStoreOpeningRequestDTO extends BaseDTO
{
    public function __construct(
        public readonly int $organizationId,
        public readonly int $requestedBy,
        public readonly string $storeName,
        public readonly string $slug,
        public readonly ?int $categoryId = null,
        public readonly ?string $description = null,
        public readonly ?string $reason = null,
    ) {}
}
