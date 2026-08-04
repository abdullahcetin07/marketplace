<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Resources;

use App\Core\Presentation\Resources\BaseResource;
use App\Core\Presentation\Support\MoneyString;
use Illuminate\Http\Request;

/**
 * One order's money, given back — as an admin sees it (Payment.md §8).
 *
 * ADMIN-ONLY, WHICH IS WHY THE PSP REFERENCE IS HERE. `PaymentResource` deliberately
 * carries nothing from the provider, because a buyer needs to know whether their
 * payment worked, not what PayTR called it. An operator reconciling a refund needs
 * exactly that string.
 *
 * MONEY AS A DECIMAL STRING (005 §28), like every amount that leaves this
 * application.
 *
 * @extends BaseResource<\App\Modules\Payment\Domain\Models\PaymentRefund>
 */
final class PaymentRefundResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'payment_id' => $this->resource->payment_uuid,
            'order_id' => $this->resource->order_uuid,
            'seller_id' => $this->resource->seller_org_uuid,
            'amount' => MoneyString::from(
                $this->resource->amount_minor,
                $this->resource->currency->decimal_places,
            ),
            'amount_minor' => $this->resource->amount_minor,
            'currency' => $this->resource->currency->code,
            'reference' => $this->resource->provider_reference,
            'reason' => $this->resource->reason,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
