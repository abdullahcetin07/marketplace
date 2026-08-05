<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Shipping\Domain\DTOs\MarkShippedDTO;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Events\ShipmentShipped;
use App\Modules\Shipping\Domain\Exceptions\ShippingException;
use App\Modules\Shipping\Domain\Models\CargoCompany;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Shared\Support\PublicKey;

/**
 * "Kargoya verdim" — the seller hands the parcel over (ADR-063, Shipping.md §6).
 *
 * THE ONLY TRANSITION A SELLER OWNS IN THIS MODULE, and the boundary is the
 * point: the next one is `delivered`, which the seller must never set because
 * payout waits on it (ADR-064). One verb in, one verb they can never reach.
 *
 * `shipped_at` IS `now()`, NEVER THE CALLER'S. A seller who could backdate a
 * handover could shorten the transit window that infers delivery — and infer
 * themselves an earlier payday. @see `MarkShippedDTO`.
 *
 * A REPEAT IS A REFUSAL, NOT A NO-OP, which is the opposite of how this codebase
 * usually treats a retry — and deliberately. An idempotent second call would
 * silently discard a corrected tracking number, or silently keep the old one;
 * both leave the buyer with a link to somebody else's parcel. A seller who typed
 * the wrong number needs an admin, and the refusal is what sends them to one.
 *
 * IT DOES NOT CHECK WHOSE ORDER THIS IS. That is `ShipmentPolicy`'s job, called
 * from the panel and any future endpoint — the same division every action here
 * keeps, so authorization exists in one place rather than in each caller of a
 * caller. The shipment carries `seller_org_uuid`, so the check needs no Order
 * lookup at all.
 *
 * @see docs/modules/Shipping.md §2, §6
 */
final class MarkShippedAction extends BaseAction
{
    private ?ShipmentShipped $shipped = null;

    public function handle(mixed ...$arguments): Shipment
    {
        /** @var MarkShippedDTO $request */
        $request = $arguments[0];

        $shipment = $this->resolve($request->shipmentUuid);

        if (! $shipment->awaitsHandover()) {
            throw ShippingException::notAwaitingHandover($shipment->uuid, $shipment->status->value);
        }

        $carrier = $this->carrier($request->cargoCompanyUuid);

        $shipment->forceFill([
            'status' => ShipmentStatus::Shipped,
            'cargo_company_id' => $carrier->getKey(),
            // Trimmed because a tracking number pasted from a carrier's SMS
            // arrives with whitespace, and a link built from " 123" is a 404.
            'tracking_number' => trim($request->trackingNumber),
            'shipped_at' => now(),
        ])->save();

        $this->shipped = new ShipmentShipped(
            shipmentUuid: $shipment->uuid,
            orderUuid: $shipment->order_uuid,
            sellerOrgUuid: $shipment->seller_org_uuid,
            cargoCompanyName: $carrier->name,
            trackingNumber: (string) $shipment->tracking_number,
            shippedAt: (string) $shipment->shipped_at?->toIso8601String(),
        );

        return $shipment;
    }

    /**
     * Dispatched AFTER COMMIT, so no consumer — a buyer notification, later a
     * carrier webhook subscription — can observe a handover a rollback undid.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->shipped !== null) {
            event($this->shipped);
        }
    }

    /**
     * BY SHAPE FIRST (ADR-059). `shipments.uuid` is a native uuid column on
     * PostgreSQL, so `where('uuid', 'nonsense')` is SQLSTATE[22P02] — a 500
     * rather than a miss. The trap, eighth watch.
     */
    private function resolve(string $shipmentUuid): Shipment
    {
        if (! PublicKey::looksLikeUuid($shipmentUuid)) {
            throw ShippingException::notFound($shipmentUuid);
        }

        $shipment = Shipment::query()->where('uuid', $shipmentUuid)->first();

        if ($shipment === null) {
            throw ShippingException::notFound($shipmentUuid);
        }

        return $shipment;
    }

    /**
     * A carrier the operator still offers.
     *
     * ACTIVE ONLY, checked here and not just in the form's options: a retired
     * carrier reaching this action means the seller's page was open when
     * operations withdrew it, and accepting it would put a dead tracking link on a
     * real parcel.
     */
    private function carrier(string $cargoCompanyUuid): CargoCompany
    {
        if (! PublicKey::looksLikeUuid($cargoCompanyUuid)) {
            throw ShippingException::carrierUnavailable($cargoCompanyUuid);
        }

        $carrier = CargoCompany::query()->active()->where('uuid', $cargoCompanyUuid)->first();

        if ($carrier === null) {
            throw ShippingException::carrierUnavailable($cargoCompanyUuid);
        }

        return $carrier;
    }
}
