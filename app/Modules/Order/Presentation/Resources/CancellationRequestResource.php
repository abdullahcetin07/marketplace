<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A cancellation request, as the buyer sees it (ADR-065, C2).
 *
 * **`decided_by` IS NOT HERE, AND THAT IS THE ONE OMISSION WORTH EXPLAINING.**
 * The buyer learns THAT the seller answered and what they said; which employee of
 * the merchant clicked the button is nobody else's business, and putting a
 * `users` id on a public surface would break non-negotiable #7 besides.
 *
 * IT CARRIES BOTH REASONS, because they belong to different people: `reason` is
 * what the buyer wrote when asking, `decision_reason` what the seller wrote when
 * refusing. A single field would make a rejection look like the buyer's own words.
 *
 * NO MONEY, because this row has none — the refund is the ORDER's story, and the
 * storefront reads that from the order (§4).
 *
 * @extends BaseResource<\App\Modules\Order\Domain\Models\CancellationRequest>
 */
final class CancellationRequestResource extends BaseResource
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
            'decision_reason' => $this->resource->decision_reason,
            'decided_at' => $this->resource->decided_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
