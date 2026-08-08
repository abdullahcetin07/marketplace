<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Resources;

use App\Core\Domain\Contracts\ShipmentQueryContract;
use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A return request, as the buyer sees it (ADR-073).
 *
 * **`return_code` IS THE FIELD THIS RESOURCE EXISTS FOR.** Everything else has a
 * counterpart on `CancellationRequestResource`; this one does not, because a
 * cancellation asks the buyer to do nothing and a return asks them to walk to a
 * cargo desk. It is null until the seller approves, which is also how the
 * storefront knows which sentence to show.
 *
 * **THE CARRIER IS A NAME, NOT A UUID.** The buyer needs to read "Yurtiçi Kargo"
 * on a screen; the identifier is the platform's business. Resolved through the
 * Core shipment port because `cargo_companies` is Shipping's table and Order may
 * not read it — the same route the seller's approval form takes.
 *
 * `decided_by` AND `completed_by` ARE NOT HERE, the same omission the cancellation
 * resource explains: the buyer learns THAT the seller answered, not which employee
 * clicked, and a `users` id on a public surface would break non-negotiable #7.
 *
 * **NO MONEY, EVEN THOUGH THIS ONE ENDS IN A REFUND.** The amount is the ORDER's
 * story — the storefront reads it there — and a figure repeated here would be a
 * second version of a number the ledger already holds.
 *
 * @extends BaseResource<\App\Modules\Order\Domain\Models\ReturnRequest>
 */
final class ReturnRequestResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'order_id' => $this->resource->order_uuid,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'reason' => $this->resource->reason,
            'lines' => $this->lines(),
            'return_code' => $this->resource->return_code,
            'cargo' => $this->cargo(),
            // The seller's words when refusing — a separate field from the
            // buyer's own `reason`, because they belong to different people.
            'decision_reason' => $this->resource->decision_reason,
            'decided_at' => $this->resource->decided_at?->toIso8601String(),
            // THE MOMENT THE MONEY MOVED. Null on every state but the last.
            'completed_at' => $this->resource->completed_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }

    /**
     * The ask, as a list rather than the stored map.
     *
     * A JSON OBJECT KEYED BY UUID IS AWKWARD FOR A CLIENT to iterate in a stable
     * order, and the storefront renders these as rows. The map is the storage
     * shape because Payment's port speaks it; this is the wire shape.
     *
     * @return array<int, array{id: string, quantity: int}>
     */
    private function lines(): array
    {
        $lines = [];

        foreach ($this->resource->line_quantities as $lineUuid => $quantity) {
            $lines[] = ['id' => (string) $lineUuid, 'quantity' => (int) $quantity];
        }

        return $lines;
    }

    /**
     * The carrier's name, or null before approval.
     *
     * READ THROUGH THE PORT, AND NULL-SAFE ON A CARRIER SINCE DISABLED: an
     * operator switching one off must not blank a return code a buyer is already
     * acting on, so the code stands and only the name goes missing.
     *
     * @return array{name: string}|null
     */
    private function cargo(): ?array
    {
        $uuid = $this->resource->cargo_company_uuid;

        if ($uuid === null || $uuid === '') {
            return null;
        }

        $name = app(ShipmentQueryContract::class)->activeCargoCompanies()[$uuid] ?? null;

        return $name === null ? null : ['name' => $name];
    }
}
