<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Shared\Enums\UserType;

/**
 * A customer walking away from their own order (§3.3).
 *
 * THE REASON IS OPTIONAL FOR A CUSTOMER and required of a SELLER on their own
 * surface — the asymmetry is deliberate. "Changed my mind" adds nothing anybody
 * will read, whereas a merchant cancelling somebody's order without saying why is
 * a support ticket waiting to happen.
 *
 * `cancelledBy` IS NOT ACCEPTED FROM THE CLIENT. It is derived from the
 * authenticated actor, because a payload that could claim `expiry` or `seller`
 * would let a customer's cancellation be attributed to the merchant.
 */
final class CancelOrderRequest extends BaseRequest
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
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
