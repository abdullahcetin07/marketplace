<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Queries;

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Modules\Inventory\Domain\Contracts\StockItemRepositoryContract;

/**
 * Inventory's implementation of the downstream read port (ADR-048).
 *
 * ITS FIRST CALLER IS THE BUY BOX. Offer's in-stock test reads availability here
 * rather than its own `stock_quantity`, so a unit held for somebody's checkout
 * stops being offered to the next shopper without Offer knowing what a
 * reservation is.
 *
 * NO STOCK RECORD MEANS ZERO, NEVER AN ERROR. A seller who never listed a
 * variant has no pool for it, and a buy box asking about one is an ordinary
 * read — throwing would turn a normal question into an exception on the
 * platform's hottest path.
 *
 * NO MEMOISATION HERE, deliberately, unlike `OfferQuery`'s store-liveness cache.
 * Availability is the number a checkout is about to act on: a value cached even
 * for the length of one request could tell two callers the same unit is free.
 * The lookup is one indexed read on `(variant_uuid, selling_org_uuid)`, which is
 * what that index exists for.
 *
 * @see App\Core\Domain\Contracts\InventoryQueryContract
 * @see docs/modules/Inventory.md §5.1
 */
final class InventoryQuery implements InventoryQueryContract
{
    public function __construct(
        private readonly StockItemRepositoryContract $items,
    ) {}

    public function availableFor(string $variantUuid, string $sellingOrgUuid): int
    {
        return $this->items->findFor($sellingOrgUuid, $variantUuid)?->available() ?? 0;
    }

    public function isAvailable(string $variantUuid, string $sellingOrgUuid, int $quantity = 1): bool
    {
        return $this->items->findFor($sellingOrgUuid, $variantUuid)?->isAvailable($quantity) ?? false;
    }

    public function onHandFor(string $variantUuid, string $sellingOrgUuid): int
    {
        // Not `?->on_hand ?? 0`: the column is a non-nullable int, so the
        // nullsafe would be the only thing that could produce null and static
        // analysis rightly calls that out. The variable makes the one real
        // question — does a pool exist — explicit.
        $item = $this->items->findFor($sellingOrgUuid, $variantUuid);

        return $item === null ? 0 : $item->on_hand;
    }
}
