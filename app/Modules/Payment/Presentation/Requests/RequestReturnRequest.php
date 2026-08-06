<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Shared\Enums\UserType;

/**
 * "Bunu geri göndereceğim" — the buyer's own return request (S4).
 *
 * IT NAMES LINES AND QUANTITIES, AND STILL NO AMOUNT. P5 established that a
 * refund names orders rather than money; S4 sharpens the same rule one level
 * down and does not relax it. The buyer knows what is going back into the box —
 * one of the two shoes — and the platform prices it from the frozen line
 * snapshot. A lira figure from a client is a client deciding what its own return
 * is worth.
 *
 * CUSTOMERS ONLY, unlike `RefundPaymentRequest`, which is admins only. They are
 * two doors to one machine deliberately: the ownership and window checks that
 * make a buyer's return legitimate are exactly the checks an admin overrides, and
 * the day one of them changes it must be obvious which door it changed.
 *
 * `uuid` VALIDATION IS THE FIRST LINE OF THE CAST-TRAP GUARD (ADR-059). Line
 * uuids reach native `uuid` columns on PostgreSQL, where a non-uuid string is
 * SQLSTATE[22P02] rather than a miss. The action filters to lines that are
 * actually on the order anyway — belt AND braces on a surface that moves money.
 *
 * @see App\Modules\Payment\Application\Actions\RequestReturnAction
 */
final class RequestReturnRequest extends BaseRequest
{
    public function authorize(): bool
    {
        // The order's OWNERSHIP is checked in the action, against the Core Order
        // port — a payment knows whose money it took, not whose order it was.
        // This only keeps other actor types off the endpoint entirely.
        return $this->actor()?->type === UserType::Customer;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Order line uuid => how many units come back.
     *
     * DUPLICATES ARE SUMMED RATHER THAN REFUSED. A client that sends the same
     * line twice means "two of these", and the remaining-quantity guard is what
     * decides whether that is allowed — refusing the shape here would move a
     * business rule into a validator.
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

    public function reason(): ?string
    {
        return $this->validated('reason');
    }
}
