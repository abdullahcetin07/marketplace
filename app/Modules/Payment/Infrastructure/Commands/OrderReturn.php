<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Commands;

use App\Core\Domain\Contracts\OrderReturnContract;
use App\Modules\Payment\Application\Actions\CompleteReturnAction;

/**
 * Payment's implementation of the return command port (ADR-073).
 *
 * **A THIN ADAPTER, EXACTLY LIKE `OrderCancellation` AND `InventoryReservation`.**
 * It holds no rule: the port exists so a foreign module can ASK, and the answer is
 * the action this module's own surfaces call. A decision here would create a
 * second version of the rules — one for callers that come through the port and one
 * for callers that do not — and the two would part company the day either changed.
 *
 * **IT RETURNS NOTHING FROM THE COMMAND.** `CompleteReturnAction` produces a
 * `PaymentRefund`; handing that model across would let Order read Payment's
 * internals through a keyhole the interface was drawn to close. The caller learns
 * success by the absence of an exception, which is all a panel button needs — and
 * all Order needs before stamping its request `Completed`.
 *
 * IN `Infrastructure`, NOT `Application`: this is a delivery mechanism — the shape
 * another context reaches the module through — the same as an HTTP controller.
 *
 * @see App\Core\Domain\Contracts\OrderReturnContract
 * @see docs/modules/Payment.md §8
 */
final class OrderReturn implements OrderReturnContract
{
    public function __construct(private readonly CompleteReturnAction $complete) {}

    public function isReturnOpen(string $orderUuid): bool
    {
        return $this->complete->isOpen($orderUuid);
    }

    /**
     * @return array<string, int>
     */
    public function returnableQuantities(string $orderUuid): array
    {
        return $this->complete->returnable($orderUuid);
    }

    /**
     * @param array<string, int> $quantities
     */
    public function completeReturnBySeller(
        string $orderUuid,
        string $sellerOrgUuid,
        array $quantities,
        ?int $actorId = null,
    ): void {
        $this->complete->run($orderUuid, $sellerOrgUuid, $quantities, $actorId);
    }
}
