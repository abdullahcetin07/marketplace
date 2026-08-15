<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Shared\Enums\UserType;

/**
 * "How much would these points be worth?" (ADR-084)
 *
 * **CUSTOMERS ONLY**, like everything else under `/loyalty`.
 * `BaseRequest::authorize()` defaults to false, so this override is the whole
 * permission.
 */
final class RedeemQuoteRequest extends BaseRequest
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
            /*
            | **BOTH OPTIONAL, AND NEITHER MEANS "SPEND EVERYTHING YOU CAN".** The
            | checkout control has two states — a slider and a "hepsini kullan"
            | toggle — and a request carrying neither is the toggle.
            |
            | An integer, because a point is a count. Asking for more than the
            | balance is not an error: the port clamps, and a slider is allowed to
            | be optimistic.
            */
            'points' => ['nullable', 'integer', 'min:0'],
            'use_max' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The points asked for, or null for "as many as possible".
     */
    public function requestedPoints(): ?int
    {
        if ($this->boolean('use_max')) {
            return null;
        }

        $points = $this->input('points');

        return $points === null ? null : max(0, (int) $points);
    }
}
