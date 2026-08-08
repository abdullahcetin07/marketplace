<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Shared\Enums\UserType;

/**
 * Asking a seller to take something back (ADR-073).
 *
 * **IT NAMES LINES AND QUANTITIES WHERE THE CANCELLATION NAMES NOTHING**, and the
 * asymmetry is ADR-065's, not an inconsistency: a buyer's cancellation is
 * whole-order because partial cancellation is the seller's own operation — they
 * know which of two units they can still ship. A return is the opposite. The
 * buyer is the one holding the box, and they are the only person who knows that
 * one of the two shoes fits.
 *
 * **THE REASON IS OPTIONAL**, the same judgement `RequestCancellationRequest`
 * makes and for a stronger reason here: Turkish distance-selling rules give the
 * buyer a no-questions right of withdrawal, so demanding a paragraph would be
 * demanding something they do not owe — and would produce a column full of "."
 * rather than a column full of information. The SELLER's rejection reason is
 * required, because refusing somebody without a word is the support ticket.
 *
 * **NO AMOUNT, EVER.** A refund names orders or lines and the platform prices
 * them from the frozen snapshot (Payment.md §8). A `quantity` a buyer can name is
 * a count of things; an amount would be a number they could argue with.
 *
 * CUSTOMERS ONLY. The seller's three answers live in the panel, deliberately not
 * as a branch on this route.
 */
final class CreateReturnRequestRequest extends BaseRequest
{
    public function authorize(): bool
    {
        // OWNERSHIP is the policy's, in the controller. This only keeps other
        // actor types off the endpoint entirely.
        return $this->actor()?->type === UserType::Customer;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            /*
            | UUIDS, VALIDATED AS UUIDS (ADR-059). `order_lines.uuid` is a native
            | uuid column on PostgreSQL, and a slug arriving here would be
            | SQLSTATE[22P02] — a 500 on a form the customer submits — rather than
            | a miss. The action refuses an unknown line anyway; this refuses the
            | shape before the query.
            */
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * The ask, flattened to the shape the action and the port both speak.
     *
     * **LAST ONE WINS ON A REPEATED LINE**, rather than summing. A client that
     * sends the same line twice has a bug, and adding the two together would
     * quietly turn "1 and 1" into a return of 2 — the buyer's screen said one.
     *
     * @return array<string, int>
     */
    public function quantities(): array
    {
        /** @var array<int, array{id: string, quantity: int}> $lines */
        $lines = $this->validated('lines');

        $quantities = [];

        foreach ($lines as $line) {
            $quantities[$line['id']] = (int) $line['quantity'];
        }

        return $quantities;
    }

    public function reason(): ?string
    {
        return $this->validated('reason');
    }
}
