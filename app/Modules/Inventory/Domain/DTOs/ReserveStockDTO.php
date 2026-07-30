<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * A request to hold units for an in-flight checkout (§0.4, ADR-049).
 *
 * IDENTIFIED BY THE CALLER'S OWN KEY. `referenceUuid` is Order's uuid, not one
 * Inventory hands back: a caller must be able to release what it reserved
 * without having stored an Inventory identifier, and a retried checkout must
 * find its own hold rather than take a second one.
 *
 * The org and variant are uuids because the caller is another bounded context
 * that holds nothing else (ADR-040). Resolving them to a stock pool — and
 * refusing when `available < qty` — is the action's job.
 */
final class ReserveStockDTO extends BaseDTO
{
    public function __construct(
        public readonly string $sellingOrgUuid,
        public readonly string $variantUuid,
        public readonly int $quantity,
        /** The caller's key. Unique, and what makes the call idempotent. */
        public readonly string $referenceUuid,
    ) {}
}
