<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Infrastructure\Queries;

use App\Core\Domain\Contracts\ShipmentQueryContract;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Models\CargoCompany;
use App\Modules\Shipping\Domain\Models\Shipment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Shipping's implementation of the downstream read port (ADR-065, §10).
 *
 * RETURNS A STRING AND A BOOLEAN, never a model — the discipline every Core query
 * contract keeps, so Payment cannot reach through the port and start driving a
 * parcel's state machine.
 *
 * **ITS FIRST CALLER ARRIVED WITH IT**, unlike `OrderQueryContract`, which shipped
 * a sprint before Payment existed. Cancellation is gated on the shipment state and
 * the gate is checked in Payment, so the port and the question were born together.
 *
 * @see App\Core\Domain\Contracts\ShipmentQueryContract
 * @see docs/modules/Shipping.md §10
 */
final class ShipmentQuery implements ShipmentQueryContract
{
    public function shipmentStatusFor(string $orderUuid): ?string
    {
        $status = Shipment::query()->where('order_uuid', $orderUuid)->value('status');

        /*
        | A STRING, not the enum: typing this with `ShipmentStatus` would make
        | every consumer import the module the port exists to avoid importing.
        |
        | `value()` comes back as the CAST value, so this is already an enum
        | instance and unwrapping it is the whole job — reading it as a string
        | would answer null for every shipment that exists.
        */
        return $status instanceof ShipmentStatus ? $status->value : null;
    }

    /**
     * THE ADR-065 GATE, answered here rather than by a caller comparing strings.
     *
     * A MISSING SHIPMENT IS FALSE, never "probably fine". Reading the absence of a
     * row as "not shipped yet" is the most expensive guess available: it refunds a
     * parcel that may already be with a carrier. An order whose shipment row never
     * arrived is a `shipping:backfill` away from being cancellable, which is a
     * smaller problem.
     */
    public function isAwaitingHandover(string $orderUuid): bool
    {
        $status = Shipment::query()->where('order_uuid', $orderUuid)->value('status');

        return $status instanceof ShipmentStatus && $status->isAwaitingHandover();
    }

    /**
     * @return array<string, string>
     */
    public function activeCargoCompanies(): array
    {
        /*
        | THE SAME QUERY THE SELLER'S SHIP FORM USES — `active()->ordered()` — so a
        | carrier disabled by an operator disappears from both pickers at once. A
        | plain array rather than a Collection: the port promises primitives, and a
        | Collection would tempt a caller into chaining Eloquent behind it.
        */
        return CargoCompany::query()->active()->ordered()->pluck('name', 'uuid')->all();
    }

    /**
     * @return array<string, string>
     */
    public function deliveredBefore(CarbonInterface $cutoff): array
    {
        /** @var array<string, string> $rows */
        $rows = DB::table('shipments')
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '<=', $cutoff)
            // A parcel that came back is not a completed sale, and one that was
            // cancelled never arrived at all.
            ->whereNull('returned_at')
            ->whereNull('cancelled_at')
            ->pluck('delivered_at', 'order_uuid')
            ->all();

        return $rows;
    }
}
