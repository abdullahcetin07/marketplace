<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Application\Jobs;

use App\Core\Application\Jobs\BaseJob;
use App\Modules\Shipping\Application\Actions\Concerns\RecordsDelivery;
use App\Modules\Shipping\Domain\Enums\DeliveredVia;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Models\Shipment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Parcels nobody said anything about (ADR-064, Shipping.md §3).
 *
 * **THE HEURISTIC THAT MAKES THE PLATFORM WORK AT ALL.** Most buyers never press
 * "Teslim aldım" — they have the goods and no reason to tell anyone. Without this
 * sweep, payout would wait on a confirmation that never comes, and a seller would
 * never be paid for a parcel that arrived weeks ago.
 *
 * IT IS ALSO THE WEAKEST SIGNAL IN THE MODULE, and the code says so: every
 * shipment it touches is stamped `delivered_via = transit_sweep`, which is what a
 * support agent reads when a buyer says "I never got it". A confirmed delivery and
 * a guessed one look identical on a status badge and are worth entirely different
 * amounts in a dispute.
 *
 * THE WINDOW IS A `settings()` VALUE, not a constant — the operations team tunes
 * it from what carriers actually do, without a release. It falls back to
 * `config('shipping.windows.transit_days')` because Settings never breaks boot by
 * design (CLAUDE.md), and a sweep that stopped running over a missing row would
 * stop paying sellers.
 *
 * IT IS DELIBERATELY GENEROUS, and the asymmetry is the reason. Too long and a
 * seller waits a few extra days to be paid. Too short and the platform tells a
 * buyer their parcel was delivered while they are still waiting for it — and
 * starts the return clock they will need.
 *
 * IDEMPOTENT AND BOUNDED, like every sweep here. It only selects shipments still
 * `shipped`, so a re-run finds nothing; one parcel whose delivery throws must not
 * strand the other ninety-nine, so each is tried on its own and the failure is
 * logged rather than propagated.
 *
 * THE EVENTS FIRE AFTER THE LOOP, not inside it. `ShipmentDelivered` starts a
 * payout clock and a return window (S3), and a listener observing one for a
 * shipment whose write then failed would schedule money against a delivery that
 * did not happen.
 *
 * @see App\Modules\Shipping\Application\Actions\Concerns\RecordsDelivery
 * @see docs/modules/Shipping.md §3
 */
final class SweepTransitDeliveriesJob extends BaseJob
{
    use RecordsDelivery;

    /**
     * How many parcels one run will infer.
     *
     * A cap, not a target: the work is idempotent, so the remainder simply waits
     * for the next run rather than holding one long transaction open.
     */
    private const int BATCH = 200;

    public function __construct()
    {
        // BaseJob subclasses must call this — the surprise documented in
        // CLAUDE.md.
        parent::__construct();
    }

    public function handle(): void
    {
        $days = $this->transitDays();

        $due = Shipment::query()
            ->where('status', ShipmentStatus::Shipped->value)
            /*
            | `shipped_at` CANNOT BE NULL ON A SHIPPED PARCEL — `MarkShippedAction`
            | writes both together — but the condition is stated anyway: a row
            | that somehow lacked it would otherwise be swept on its first pass,
            | because `null < now()` is not the comparison anybody intended.
            */
            ->whereNotNull('shipped_at')
            ->where('shipped_at', '<=', now()->subDays($days))
            ->limit(self::BATCH)
            ->get();

        if ($due->isEmpty()) {
            return;
        }

        $events = [];

        foreach ($due as $shipment) {
            $event = $this->infer($shipment);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        foreach ($events as $event) {
            event($event);
        }

        Log::channel('audit')->info('Inferred delivery from the transit window', [
            'shipments' => count($events),
            'transit_days' => $days,
        ]);
    }

    /**
     * One parcel, on its own, so a single failure does not strand the rest.
     */
    private function infer(Shipment $shipment): ?object
    {
        try {
            return $this->recordDelivery($shipment, DeliveredVia::TransitSweep);
        } catch (Throwable $exception) {
            /*
             * Logged, not rethrown. Rethrowing would fail the whole batch and
             * retry it — re-delivering the parcels that already succeeded (a
             * no-op, but wasted) and stopping at the same bad row every time. The
             * next run tries this one again.
             */
            Log::channel('errors')->warning('Could not infer a delivery from the transit window', [
                'shipment_uuid' => $shipment->uuid,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * How long a parcel may be in transit before the platform believes it
     * arrived.
     *
     * `settings()` FIRST, config as the floor. @see the class docblock.
     */
    private function transitDays(): int
    {
        $fallback = (int) config('shipping.windows.transit_days', 3);

        return max(1, (int) settings('shipping.transit_days', $fallback));
    }
}
