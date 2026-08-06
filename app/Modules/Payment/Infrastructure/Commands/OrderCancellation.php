<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Commands;

use App\Core\Domain\Contracts\OrderCancellationContract;
use App\Modules\Payment\Application\Actions\CancelOrderLinesAction;

/**
 * Payment's implementation of the cancellation command port (ADR-065, C1).
 *
 * **A THIN ADAPTER, EXACTLY LIKE `InventoryReservation`.** It holds no logic at
 * all: the port exists so a foreign module can ASK, and the answer is the action
 * the module's own surfaces already call. Putting a decision here would create a
 * second version of the rules — one for callers that come through the port and one
 * for callers that do not — and the two would part company the day either changed.
 *
 * **IT RETURNS NOTHING, AND THAT IS THE POINT.** `CancelOrderLinesAction` produces
 * a `PaymentRefund`; handing that model across the port would let Order read
 * Payment's internals through a keyhole the interface was drawn to close. The
 * caller learns success by the absence of an exception, which is all a panel
 * button needs.
 *
 * IN `Infrastructure`, NOT `Application`, for the reason the layout gives: this is
 * a delivery mechanism — the shape another context reaches the module through —
 * the same as an HTTP controller or a console command.
 *
 * @see App\Core\Domain\Contracts\OrderCancellationContract
 * @see docs/modules/Payment.md §8
 */
final class OrderCancellation implements OrderCancellationContract
{
    public function __construct(private readonly CancelOrderLinesAction $cancel) {}

    /**
     * @param array<string, int> $quantities
     */
    public function cancelLinesBySeller(
        string $orderUuid,
        string $sellerOrgUuid,
        array $quantities,
        ?string $reason = null,
        ?int $actorId = null,
    ): void {
        $this->cancel->run($orderUuid, $sellerOrgUuid, $quantities, $reason, $actorId);
    }

    /**
     * @return array<string, int>
     */
    public function cancellableQuantities(string $orderUuid, string $sellerOrgUuid): array
    {
        return $this->cancel->cancellable($orderUuid, $sellerOrgUuid);
    }
}
