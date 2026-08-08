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
        // ADMIN-ONLY, AND STILL SO AFTER S4. The buyer's own return is a
        // different endpoint with different guards (Order's
        // `CreateReturnRequestRequest`, ADR-073),
        // not this one relaxed. The policy is still checked in the controller;
        // this only keeps other user types off the endpoint entirely.
        return $this->actor()?->type === UserType::Admin;
    }

    /**
     * `order_id` + `lines` ARE THE S4 HALF, and they are mutually exclusive with
     * `order_ids` by meaning rather than by a validation rule: naming lines is
     * naming ONE order, so the singular field is what carries it.
     *
     * WHY AN ADMIN NEEDS THEM AT ALL. S4 let a buyer send back one of two shoes.
     * An order with a partial return can no longer be whole-refunded — the
     * whole-order path skips it, correctly, because refunding it again would
     * refund the returned shoe twice. Without a line-level admin path, that order
     * would be stuck partly refunded forever with no way to finish it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_ids' => ['sometimes', 'array'],
            'order_ids.*' => ['uuid'],
            'order_id' => ['required_with:lines', 'uuid'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The single order a line-level refund is against; null for the whole-order
     * path.
     */
    public function orderUuid(): ?string
    {
        $order = $this->validated('order_id');

        return is_string($order) ? $order : null;
    }

    /**
     * Order line uuid => how many units come back; empty for the whole-order
     * path. Duplicates are summed here; Order's `CreateReturnRequestRequest`
     * takes the last instead — a buyer's screen said one number, an admin's form
     * is a worksheet.
     *
     * @return array<string, int>
     */
    public function quantities(): array
    {
        /** @var array<int, array{id: string, quantity: int}> $lines */
        $lines = $this->validated('lines') ?? [];

        $quantities = [];

        foreach ($lines as $line) {
            $id = (string) $line['id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) $line['quantity'];
        }

        return $quantities;
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
