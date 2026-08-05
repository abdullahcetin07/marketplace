<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Listeners;

use App\Modules\Payment\Domain\Models\SettlementWindow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A parcel arrived, so two clocks start (ADR-064, Shipping.md §4).
 *
 * **THIS IS WHAT SHIPPING WAS BUILT FOR.** ADR-060 left payout manual only and P5
 * left the customer refund admin-only, both because there was no notion of
 * delivery. There is now, and this listener is where the money side picks it up:
 * the seller becomes payable at `delivered_at + payout_hold_days`, and the buyer
 * may return until `delivered_at + return_days`.
 *
 * SUBSCRIBED BY CLASS-STRING, so Payment imports nothing from Shipping — and
 * Shipping names nothing here either. Neither module knows the other exists; the
 * event's SHAPE is the whole contract, which is why the handler takes an untyped
 * `object` and reads public properties.
 *
 * ITS COST, stated as every other class-string subscription states it: a rename in
 * Shipping breaks this at RUNTIME, not at build time. Bounded the same way — a
 * feature test that drives the real confirmation and asserts the window opened.
 *
 * **`delivered_at` COMES FROM THE EVENT, NEVER FROM THE CLOCK.** This may run on a
 * queue, minutes or hours after the parcel was marked delivered, and computing
 * `now() + 14 days` here would push a seller's payday out by exactly however long
 * the queue was behind. The payload carries the date for this reason alone.
 *
 * THE WINDOWS ARE FROZEN, not derived on read. @see `SettlementWindow` — an
 * operator shortening the hold must not make last month's deliveries retroactively
 * payable, nor lengthening it withdraw a payout already promised.
 *
 * IDEMPOTENT AT THE DATABASE. Shipping's own guard refuses a second delivery, but
 * an event can be replayed by a queue retry; the UNIQUE index on `order_uuid` is
 * what stops the replay pushing the dates out. A repeat is silence, not an error.
 *
 * @see docs/modules/Payment.md §8
 */
final class OpenSettlementWindows
{
    /**
     * `App\Modules\Shipping\Domain\Events\ShipmentDelivered` — untyped on purpose.
     */
    public function handle(object $event): void
    {
        $orderUuid = (string) ($event->orderUuid ?? '');
        $sellerOrgUuid = (string) ($event->sellerOrgUuid ?? '');
        $deliveredAt = $this->deliveredAt($event);

        if ($orderUuid === '' || $sellerOrgUuid === '' || $deliveredAt === null) {
            Log::channel('errors')->error('A delivery event arrived without enough to open a settlement window', [
                'order_uuid' => $orderUuid,
                'seller_org_uuid' => $sellerOrgUuid,
                'delivered_at' => $event->deliveredAt ?? null,
            ]);

            return;
        }

        // The ordinary replay path. Not an error — the windows are already open
        // and must not move.
        if (SettlementWindow::query()->where('order_uuid', $orderUuid)->exists()) {
            return;
        }

        try {
            SettlementWindow::query()->create([
                'order_uuid' => $orderUuid,
                'seller_org_uuid' => $sellerOrgUuid,
                'delivered_at' => $deliveredAt,
                'delivered_via' => (string) ($event->deliveredVia ?? ''),
                'payout_eligible_at' => $deliveredAt->copy()->addDays($this->days('payout_hold_days', 14)),
                'return_window_ends_at' => $deliveredAt->copy()->addDays($this->days('return_days', 14)),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two deliveries of the same event raced. The index did its job.
        }
    }

    /**
     * The delivery date as the event stated it.
     *
     * A MALFORMED DATE IS A REFUSAL, NOT A GUESS. Falling back to `now()` would
     * silently produce a payout schedule nobody intended, and the whole point of
     * carrying the timestamp is that this listener does not get to decide it.
     */
    private function deliveredAt(object $event): ?Carbon
    {
        $raw = $event->deliveredAt ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A window in days: the operator's setting, then the deployed default.
     *
     * `settings()` NEVER BREAKS BOOT by design (CLAUDE.md), so an unreachable
     * settings table falls through to config rather than throwing — a delivery
     * that failed to open its windows would leave a seller unpayable with nothing
     * to retry.
     */
    private function days(string $key, int $default): int
    {
        $fallback = (int) config("shipping.windows.{$key}", $default);

        return max(0, (int) settings("shipping.{$key}", $fallback));
    }
}
