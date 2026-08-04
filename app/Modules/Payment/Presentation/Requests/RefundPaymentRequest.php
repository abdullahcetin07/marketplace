<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Shared\Enums\UserType;

/**
 * Asking for money to go back (Payment.md §8, P5).
 *
 * THERE IS NO AMOUNT FIELD, and that is the shape of the whole feature. A refund
 * names ORDERS — the unit the buyer recognises, the unit the seller's ledger is
 * keyed on, and the unit whose stock can be put back. A lira figure could be none
 * of those. @see `RefundRequestDTO`.
 *
 * AN EMPTY LIST MEANS THE WHOLE PAYMENT, spelled as the absence of a choice rather
 * than as a flag — the common case should not be the one a caller can forget to
 * set.
 *
 * `uuid` VALIDATION IS THE FIRST LINE OF THE CAST TRAP GUARD (ADR-059). Order
 * uuids reach a native `uuid` column on PostgreSQL, where a non-uuid string is
 * SQLSTATE[22P02] rather than a miss. The action filters to what is actually in
 * the payment's group anyway, so this is belt AND braces on the one endpoint that
 * moves money out.
 */
final class RefundPaymentRequest extends BaseRequest
{
    public function authorize(): bool
    {
        // Admin-only in v1 — see `PaymentPolicy::refund()` for why the customer
        // half of the spec is deliberately not wired yet. The policy is still
        // checked in the controller; this only keeps other user types off the
        // endpoint entirely.
        return $this->actor()?->type === UserType::Admin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_ids' => ['sometimes', 'array'],
            'order_ids.*' => ['uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function orderUuids(): array
    {
        /** @var array<int, string> $orders */
        $orders = $this->validated('order_ids') ?? [];

        return array_values($orders);
    }

    public function reason(): ?string
    {
        return $this->validated('reason');
    }
}
