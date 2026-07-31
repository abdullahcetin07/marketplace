<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Jobs;

use App\Core\Application\Jobs\BaseJob;
use App\Modules\Order\Application\Actions\CancelOrderAction;
use App\Modules\Order\Domain\Contracts\OrderRepositoryContract;
use App\Modules\Order\Domain\DTOs\CancelOrderDTO;
use App\Modules\Order\Domain\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Give back the stock of checkouts nobody finished (§3.3, ADR-054).
 *
 * THE COUNTERWEIGHT TO RESERVING AT CHECKOUT. A customer who opens a checkout and
 * closes the tab has taken units out of every other buyer's reach, and will never
 * release them — nobody abandons a basket on purpose and then tidies up. Without
 * this job, one abandoned checkout removes a seller's last unit permanently.
 *
 * IT SWEEPS UNPLACED CHECKOUTS ONLY (ADR-057), and that boundary matters more since
 * placement stopped committing. A placed order holds a reservation too — but it is
 * not an abandoned tab, it is a purchase the customer believes they have made, and
 * it holds until it is paid or cancelled however long that takes. Expiring one
 * would cancel somebody's order out from under them to free stock they are about
 * to pay for. `expiredPending()` filters on `Pending` for exactly this reason.
 *
 * INVENTORY SHIPPED THE RELEASE PRIMITIVE FOR EXACTLY THIS (ADR-049), and Order
 * is the module with the clock. The window is config
 * (`order.reservation.expires_after_minutes`, 30 by default) because the right
 * answer depends on a payment provider's own timeout, which arrives with Payment.
 *
 * IT GOES THROUGH `CancelOrderAction`, NOT STRAIGHT TO INVENTORY. A swept order is
 * a cancelled order — it must move to `Cancelled`, release its holds and raise
 * `OrderCancelled` exactly as a customer's cancellation does. A job that released
 * the stock behind the action's back would leave orders sitting `Pending` forever
 * with no hold behind them, which reads as "still checking out" on every surface.
 *
 * `cancelledBy: expiry` IS THE POINT OF THAT FIELD. It is the one cancellation a
 * seller most needs told apart from a customer changing their mind, and the sweep
 * has no actor to derive it from.
 *
 * BOUNDED PER RUN, and it keeps going after a failure. This runs forever against a
 * table that only grows; one order whose release throws must not stop the other
 * ninety-nine from being freed, so each is tried on its own and the failure is
 * logged rather than propagated. The next run picks the straggler up again —
 * which is also why the whole thing is safe to run as often as you like.
 *
 * @see App\Modules\Order\Application\Actions\CancelOrderAction
 * @see docs/modules/Order.md §3.3
 */
final class ExpireReservationsJob extends BaseJob
{
    /**
     * How many expired orders one run will handle.
     *
     * A cap, not a target: a sweep that tried to release every expired order in
     * one pass would hold a transaction open for minutes on a busy platform, and
     * the work is idempotent so the remainder simply waits for the next run.
     */
    private const int BATCH = 100;

    public function __construct()
    {
        // BaseJob subclasses must call this — the surprise documented in
        // CLAUDE.md.
        parent::__construct();
    }

    public function handle(
        OrderRepositoryContract $orders,
        CancelOrderAction $cancel,
    ): void {
        $expired = $orders->expiredPending(self::BATCH);

        if ($expired->isEmpty()) {
            return;
        }

        foreach ($expired as $order) {
            $this->expire($order, $cancel);
        }

        Log::channel('audit')->info('Expired checkout reservations released', [
            'orders' => $expired->count(),
            'window_minutes' => (int) config('order.reservation.expires_after_minutes', 30),
        ]);
    }

    /**
     * One order, on its own, so a single failure does not strand the rest.
     */
    private function expire(Order $order, CancelOrderAction $cancel): void
    {
        try {
            $cancel->run($order, new CancelOrderDTO(
                cancelledBy: CancelOrderDTO::BY_EXPIRY,
                reason: __('order.cancel.expired'),
            ));
        } catch (\Throwable $exception) {
            /*
             * Logged, not rethrown. Rethrowing would fail the whole batch and
             * retry it — re-cancelling the orders that already succeeded (a
             * no-op, but wasted) and stopping at the same bad row every time.
             * The next run tries this one again.
             */
            Log::channel('errors')->warning('Could not expire an order reservation', [
                'order_uuid' => $order->uuid,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
