<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use App\Core\Presentation\Support\MoneyString;
use Illuminate\Http\Request;

/**
 * A payout, as the admin surface sees it (Payment.md §8).
 *
 * MONEY AS A DECIMAL STRING (005 §28), never the integer kuruş — most clients
 * parse a JSON number as a float, which is what this module's arithmetic exists
 * to avoid.
 *
 * NO INTERNAL IDS: the seller is a uuid and the admins are omitted entirely. Who
 * authorised a transfer is audit material, not something a payout list needs to
 * broadcast.
 *
 * @extends BaseResource<\App\Modules\Payment\Domain\Models\Payout>
 */
final class PayoutResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'seller_id' => $this->resource->seller_org_uuid,
            'amount' => MoneyString::from(
                $this->resource->amount_minor,
                $this->resource->currency->decimal_places,
            ),
            'currency' => $this->resource->currency->code,
            'status' => $this->resource->status->value,
            'reference' => $this->resource->external_reference,
            'failure_reason' => $this->resource->failure_reason,
            'note' => $this->resource->note,
            'paid_at' => $this->resource->paid_at?->toIso8601String(),
            'failed_at' => $this->resource->failed_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
