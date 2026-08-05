<?php

declare(strict_types=1);

namespace Database\Modules\Shipping\Factories;

use App\Modules\Shipping\Domain\Enums\DeliveredVia;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Models\CargoCompany;
use App\Modules\Shipping\Domain\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Shipment>
 */
final class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_uuid' => (string) Str::uuid(),
            'seller_org_uuid' => (string) Str::uuid(),
            'order_number' => 'SP-'.Str::upper(Str::random(8)),
            'status' => ShipmentStatus::Pending,
        ];
    }

    public function forSeller(string $sellerOrgUuid): self
    {
        return $this->state(fn (): array => ['seller_org_uuid' => $sellerOrgUuid]);
    }

    public function forOrder(string $orderUuid, ?string $orderNumber = null): self
    {
        return $this->state(fn (): array => array_filter([
            'order_uuid' => $orderUuid,
            'order_number' => $orderNumber,
        ]));
    }

    /**
     * In transit — with a real carrier row, because a shipped parcel without one
     * is a state the action cannot produce.
     */
    public function shipped(): self
    {
        return $this->state(fn (): array => [
            'status' => ShipmentStatus::Shipped,
            'cargo_company_id' => CargoCompany::factory(),
            'tracking_number' => (string) Str::upper(Str::random(12)),
            'shipped_at' => now()->subDay(),
        ]);
    }

    /**
     * Delivered — S2's outcome, available now so S1's transition table can be
     * asserted against a real row.
     */
    public function delivered(DeliveredVia $via = DeliveredVia::Buyer): self
    {
        return $this->shipped()->state(fn (): array => [
            'status' => ShipmentStatus::Delivered,
            'delivered_at' => now(),
            'delivered_via' => $via,
        ]);
    }
}
