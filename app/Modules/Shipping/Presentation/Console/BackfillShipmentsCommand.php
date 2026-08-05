<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Presentation\Console;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Events\ShipmentCreated;
use App\Modules\Shipping\Domain\Models\Shipment;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Give every already-paid order the parcel it never got.
 *
 * THE DEPLOY STEP THIS MODULE NEEDS ONCE. Shipments are created by a listener on
 * the payment event, which covers everything paid from the moment this ships — and
 * nothing paid before it. The platform already has paid orders, and without this
 * their sellers would have no way to mark them shipped and no way to ever be paid
 * out for them (payout waits on delivery, ADR-064).
 *
 * IT IS ALSO THE REPAIR for a lost event: a listener that threw, a queue that was
 * drained, a payment settled while this module was being deployed. Re-running it
 * is free.
 *
 * IDEMPOTENT TWICE OVER, the same shape as the listener: it skips what exists, and
 * the UNIQUE index on `order_uuid` catches the race it cannot see. A second parcel
 * for one order is the failure both guards exist to prevent.
 *
 * IT READS ORDER THROUGH THE CORE PORT and asks for a bounded page, because "every
 * paid order" grows without limit. `--limit` is how an operator drains a large
 * backlog in passes rather than in one query that cannot finish.
 *
 * @see App\Modules\Shipping\Application\Listeners\CreateShipmentsOnPayment
 */
final class BackfillShipmentsCommand extends Command
{
    protected $signature = 'shipping:backfill
                            {--seller= : Only this seller organization uuid}
                            {--limit=500 : How many paid orders to examine}
                            {--dry-run : Report what would be created without writing}';

    protected $description = 'Create shipments for paid orders that do not have one yet';

    public function __construct(private readonly OrderQueryContract $orders)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $seller = $this->option('seller');
        $orderUuids = $this->orders->paidOrders(
            is_string($seller) && $seller !== '' ? $seller : null,
            (int) $this->option('limit'),
        );

        if ($orderUuids === []) {
            $this->components->info('No paid orders found.');

            return self::SUCCESS;
        }

        // ONE QUERY FOR WHAT EXISTS, not one per order: a backfill over a real
        // backlog is exactly where an N+1 stops being theoretical.
        $existing = Shipment::query()->whereIn('order_uuid', $orderUuids)->pluck('order_uuid')->all();
        $missing = array_values(array_diff($orderUuids, $existing));

        if ($missing === []) {
            $this->components->info('Every paid order already has a shipment ('.count($orderUuids).' examined).');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->warn(count($missing).' shipment(s) would be created. Nothing was written.');

            return self::SUCCESS;
        }

        $created = 0;

        foreach ($missing as $orderUuid) {
            $created += $this->create($orderUuid) ? 1 : 0;
        }

        $this->components->info("Created {$created} shipment(s) from ".count($orderUuids).' paid order(s).');

        return self::SUCCESS;
    }

    private function create(string $orderUuid): bool
    {
        $fulfilment = $this->orders->orderFulfilment($orderUuid);

        if ($fulfilment === null) {
            $this->components->error("Order {$orderUuid} did not resolve; skipped.");

            return false;
        }

        try {
            $shipment = Shipment::query()->create([
                'order_uuid' => $orderUuid,
                'seller_org_uuid' => $fulfilment['selling_org_uuid'],
                'order_number' => $fulfilment['order_number'],
                'status' => ShipmentStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The listener got there first, between the read above and this
            // write. The index did its job.
            return false;
        }

        ShipmentCreated::dispatch(
            $shipment->uuid,
            $shipment->order_uuid,
            $shipment->seller_org_uuid,
            $shipment->order_number,
        );

        return true;
    }
}
