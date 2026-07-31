<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Shared\Enums\UserType;

/**
 * Turn the basket into orders (ADR-052/056).
 *
 * BOTH ADDRESSES ARE REQUIRED, and neither defaults to the other. A client that
 * wants them the same sends the same uuid twice, which is explicit — inferring
 * "billing = shipping when omitted" would silently put a home address on a
 * company's invoice, and nobody would notice until an accountant did.
 *
 * OWNERSHIP IS NOT VALIDATED HERE. `exists:` on the uuid alone would confirm that
 * somebody else's address is real; the action looks both up BY UUID AND OWNER, so
 * "not yours" and "does not exist" produce one indistinguishable refusal.
 */
final class CheckoutRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->actor()?->type === UserType::Customer;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shipping_address_id' => ['required', 'uuid'],
            'billing_address_id' => ['required', 'uuid'],
        ];
    }

    public function toDto(): CheckoutDTO
    {
        return new CheckoutDTO(
            shippingAddressUuid: (string) $this->validated('shipping_address_id'),
            billingAddressUuid: (string) $this->validated('billing_address_id'),
        );
    }
}
