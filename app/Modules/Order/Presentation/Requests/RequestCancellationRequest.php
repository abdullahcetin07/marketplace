<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Shared\Enums\UserType;

/**
 * Asking a seller to cancel (ADR-065, C2).
 *
 * **THE REASON IS OPTIONAL, UNLIKE ON EVERY CANCEL LEVER AROUND IT.** A seller
 * refusing somebody's order owes them a sentence and a seller rejecting a request
 * owes them one too — both surfaces demand it. A buyer changing their mind owes
 * nobody an explanation, and demanding one here would produce a field full of
 * "." rather than a field full of information.
 *
 * IT TAKES NOTHING ELSE. Not the lines, not a quantity, not an amount: ADR-065
 * makes the buyer's request WHOLE-ORDER, because partial cancellation is the
 * seller's own operation (C1) — they are the ones who know which of two units
 * they can still ship.
 *
 * CUSTOMERS ONLY. The seller's side of this exchange is the panel inbox, not this
 * endpoint, and the two must not be one route with a branch: they are the two
 * halves of an argument, and a surface that could serve both is a surface where
 * one of them can act as the other.
 */
final class RequestCancellationRequest extends BaseRequest
{
    public function authorize(): bool
    {
        // The order's OWNERSHIP is checked by the policy in the controller. This
        // only keeps other actor types off the endpoint entirely.
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

    public function reason(): ?string
    {
        return $this->validated('reason');
    }
}
