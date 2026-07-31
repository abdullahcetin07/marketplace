<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Order\Domain\DTOs\UpdateCartItemDTO;
use App\Shared\Enums\UserType;

/**
 * Change a basket line's quantity.
 *
 * AN ABSOLUTE QUANTITY, never a delta — a delta over an unreliable network is how
 * a double-tapped `+` becomes five items.
 *
 * `min:1` RATHER THAN `min:0`: zero is not a delete. Overloading it would make
 * "set it to what the box says" silently destructive when a customer clears the
 * field to retype it — `DELETE` is the route that removes a line.
 */
final class UpdateCartItemRequest extends BaseRequest
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
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function toDto(): UpdateCartItemDTO
    {
        return new UpdateCartItemDTO(quantity: (int) $this->validated('quantity'));
    }
}
