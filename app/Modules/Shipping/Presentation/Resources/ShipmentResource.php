<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A parcel, as its buyer sees it (Shipping.md §6).
 *
 * WHAT "SİPARİŞLERİM" RENDERS: where the parcel is, who is carrying it, and a
 * link to follow. `tracking_url` is BUILT rather than stored — from the carrier's
 * own template — which is why the template is a column and not a `match` in
 * frontend code (§5).
 *
 * `can_confirm_receipt` IS COMPUTED HERE so the storefront does not re-derive
 * "may I show the Teslim aldım button". The rule is one thing — the parcel is in
 * transit — and a client that decided for itself would eventually show a button
 * the API refuses.
 *
 * THE SELLER ORG IS ABSENT, deliberately. A buyer's order page already names the
 * store; the organization uuid behind it is internal vocabulary and nothing on
 * this surface needs it.
 *
 * `delivered_via` IS EXPOSED, and it is the one field here a buyer might argue
 * with: "kargo süresi doldu" is the platform saying it ASSUMED delivery. Showing
 * it is better than hiding it — a buyer who never received the parcel needs to
 * see that nobody actually confirmed it.
 *
 * @extends BaseResource<\App\Modules\Shipping\Domain\Models\Shipment>
 */
final class ShipmentResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'order_id' => $this->resource->order_uuid,
            'order_number' => $this->resource->order_number,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'carrier' => $this->resource->cargoCompany?->name,
            'tracking_number' => $this->resource->tracking_number,
            'tracking_url' => $this->resource->trackingUrl(),
            'shipped_at' => $this->resource->shipped_at?->toIso8601String(),
            'delivered_at' => $this->resource->delivered_at?->toIso8601String(),
            'delivered_via' => $this->resource->delivered_via?->value,
            // The one rule the "Teslim aldım" button follows, decided once.
            'can_confirm_receipt' => $this->resource->status->isInTransit(),
        ];
    }
}
