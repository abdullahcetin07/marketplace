<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Shared\Enums\UserType;

/**
 * Add an offer to the basket.
 *
 * AUTHENTICATED CUSTOMERS ONLY (ADR-056, owner decision): no guest checkout in
 * v1, so a basket belongs to an account from the first item rather than being
 * migrated from a session at login.
 *
 * NO PRICE FIELD, and no `variant_id` or `seller_id` either. A client that could
 * send any of those could put a cheap offer's price on an expensive line — every
 * one of them is read from the offer (§2.1).
 */
final class AddCartItemRequest extends BaseRequest
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
            'offer_id' => ['required', 'uuid'],
            // The real ceiling is the configured guard rail and, under that,
            // availability — both enforced in the action. This only stops
            // nonsense reaching it.
            'quantity' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function toDto(): AddCartItemDTO
    {
        return new AddCartItemDTO(
            offerUuid: (string) $this->validated('offer_id'),
            quantity: (int) ($this->validated('quantity') ?? 1),
        );
    }
}
