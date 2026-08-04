<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use App\Core\Presentation\Support\MoneyString;
use Illuminate\Http\Request;

/**
 * A payment, as its owner sees it (Payment.md §3).
 *
 * WHAT THE RESULT PAGE ASKS FOR after PayTR sends the browser back. The status
 * here is what the SERVER learned from the callback — the only thing worth
 * trusting, since a redirect's query string is whatever the browser was handed.
 *
 * MONEY AS A DECIMAL STRING (005 §28), never the integer kuruş: most clients parse
 * a JSON number as a float, which is the one thing this module's arithmetic exists
 * to avoid.
 *
 * NOTHING FROM THE PSP CROSSES IT. No provider reference, no raw payload, no
 * failure code — those are support and audit material, and a buyer needs to know
 * whether their payment worked, not what a bank's decline code was.
 *
 * @extends BaseResource<\App\Modules\Payment\Domain\Models\Payment>
 */
final class PaymentResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'checkout_group_id' => $this->resource->checkout_group_uuid,
            'status' => $this->resource->status->value,
            'amount' => MoneyString::from(
                $this->resource->amount_minor,
                $this->resource->currency->decimal_places,
            ),
            'currency' => $this->resource->currency->code,
            'paid_at' => $this->resource->paid_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
