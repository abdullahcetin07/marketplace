<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Commands;

use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Modules\Inventory\Application\Actions\CommitStockAction;
use App\Modules\Inventory\Application\Actions\ReleaseStockAction;
use App\Modules\Inventory\Application\Actions\ReserveStockAction;
use App\Modules\Inventory\Domain\DTOs\ReserveStockDTO;

/**
 * Inventory's implementation of the platform's first COMMAND port (ADR-049).
 *
 * A THIN ADAPTER, DELIBERATELY. It holds no rule: each verb builds a DTO and
 * hands it to the action that owns the transaction, the row lock, the ledger
 * write and the event. Anything cleverer here would be a second place stock
 * logic lives — reachable only through the contract, so the panel and the tests
 * would exercise a different path from Order.
 *
 * WHY IT IS INFRASTRUCTURE and not Application: it is an adapter between a Core
 * port and this module's actions, the same layer and the same role as
 * `InventoryQuery` and `CatalogBrowse`. The Application layer is what it calls.
 *
 * ORDER IS ITS FIRST CALLER AND ORDER DOES NOT EXIST. This sprint the tests are
 * the only caller — which is exactly the state ADR-049 chose: build the authority
 * before the thing that needs it, so the thing that needs it finds a contract
 * with tests rather than a design decision to make in a hurry.
 *
 * @see App\Core\Domain\Contracts\InventoryReservationContract
 * @see docs/modules/Inventory.md §5.2
 */
final class InventoryReservation implements InventoryReservationContract
{
    public function __construct(
        private readonly ReserveStockAction $reserve,
        private readonly ReleaseStockAction $release,
        private readonly CommitStockAction $commit,
    ) {}

    /**
     * Returns true when the hold stands — including when this reference already
     * held it. A failure is an exception, not a false: "there is not enough" and
     * "that variant is not stocked" are different answers and a caller usually
     * needs to know which.
     */
    public function reserve(
        string $sellingOrgUuid,
        string $variantUuid,
        int $quantity,
        string $reference,
    ): bool {
        $this->reserve->run(new ReserveStockDTO(
            sellingOrgUuid: $sellingOrgUuid,
            variantUuid: $variantUuid,
            quantity: $quantity,
            reference: $reference,
        ));

        return true;
    }

    public function release(string $reference): void
    {
        $this->release->run($reference);
    }

    public function commit(string $reference): void
    {
        $this->commit->run($reference);
    }
}
