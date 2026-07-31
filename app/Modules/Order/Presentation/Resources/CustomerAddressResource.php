<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Resources;

use App\Modules\Order\Domain\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One address in the customer's book (ADR-056).
 *
 * `id` IS THE UUID (non-negotiable #7) and `country_id` is absent entirely — the
 * country crosses as its ISO code, which is what the client sent and what a
 * snapshot will freeze.
 *
 * @mixin CustomerAddress
 */
final class CustomerAddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'label' => $this->label,
            'recipient_name' => $this->recipient_name,
            'phone' => $this->phone,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'district' => $this->district,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'country' => $this->country->iso2,
            'is_default_shipping' => $this->is_default_shipping,
            'is_default_billing' => $this->is_default_billing,
        ];
    }
}
