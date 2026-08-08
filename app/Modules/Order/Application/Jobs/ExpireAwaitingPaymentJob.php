<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Jobs;

use App\Core\Application\Jobs\BaseJob;
use App\Modules\Order\Application\Actions\ExpireOrderAction;
use App\Modules\Order\Domain\Contracts\OrderRepositoryContract;
use App\Modules\Order\Domain\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every minute: give back the stock of orders nobody paid for (ADR-072).
 *
 * **SEPARATE FROM `ExpireReservationsJob`, WHICH SWEEPS A DIFFERENT WINDOW.**
 * That one handles `Pending` — a basket abandoned at the address step, 30
 * minutes, ending in `Cancelled`. This handles `AwaitingPayment` — placed, sent
 * to the card form, never paid — ending in `Expired`. Merging them would mean one
 * job with two windows, two outcomes and two reasons, and the day somebody tuned
 * one they would tune the other.
 *
 * **EVERY MINUTE, NOT HOURLY**, because the window is five: a sweep slower than
 * the window it enforces means an abandoned hold outlives its own deadline by up
 * to the sweep interval, and the whole point is giving a seller's availability
 * back promptly.
 *
 * **THE FIRST RUN SELF-HEALS THE BACKLOG.** Every order stuck in
 * `AwaitingPayment` since before this shipped is past the window by definition,
 * so the first pass releases all of them (a batch at a time) — no separate
 * cleanup script, and a seller whose listing had silently dropped off the buy box
 * reappears within minutes.
 *
 * ONE ORDER AT A TIME, each in its own action transaction, so a single bad row
 * cannot strand the rest of the batch.
 *
 * @see App\Modules\Order\Application\Actions\ExpireOrderAction
 */
final class ExpireAwaitingPaymentJob extends BaseJob
{
    /**
     * A cap, not a target. The work is idempotent and runs every minute, so the
     * remainder of a large backlog simply waits sixty seconds.
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
        ExpireOrderAction $expire,
    ): void {
        $window = self::paymentWindowMinutes();
        $expiring = $orders->awaitingPaymentExpired($window, self::BATCH);

        if ($expiring->isEmpty()) {
            return;
        }

        foreach ($expiring as $order) {
            $this->expire($order, $expire);
        }

        Log::channel('audit')->info('Expired unpaid orders and released their holds', [
            'orders' => $expiring->count(),
            'window_minutes' => $window,
        ]);
    }

    /**
     * The operator's number, with the config as a floor.
     *
     * SETTINGS FIRST, CONFIG UNDER IT — the shape `SweepTransitDeliveriesJob`
     * established. `settings()` never breaks boot by design (CLAUDE.md), so an
     * unreachable Settings table degrades to the shipped default rather than
     * stopping the sweep that keeps sellers' stock available.
     *
     * FLOORED AT ONE MINUTE: a window of zero would expire an order in the same
     * breath as placing it, before the customer ever reached the card form.
     */
    public static function paymentWindowMinutes(): int
    {
        return max(1, (int) settings(
            'order.payment_window_minutes',
            (int) config('order.payment_window_minutes', 5),
        ));
    }

    /**
     * One order, on its own, so a single failure does not strand the batch.
     */
    private function expire(Order $order, ExpireOrderAction $expire): void
    {
        try {
            $expire->run($order);
        } catch (Throwable $exception) {
            /*
             * Logged, not rethrown. Rethrowing would fail the whole batch and
             * retry it, re-running the orders that already succeeded and
             * stopping at the same bad row every minute. The next run tries this
             * one again.
             */
            Log::channel('errors')->warning('Could not expire an unpaid order', [
                'order_uuid' => $order->uuid,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
