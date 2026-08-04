<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Shared\Enums\UserType;

/**
 * Creating a payout — admin only (Payment.md §8).
 *
 * THE AMOUNT IS IN KURUŞ AND IS AN INTEGER, because it is money (ADR-005). A
 * decimal here would be the one place a float could enter the payout chain, and
 * the action would then debit a seller's balance by something it could not
 * reproduce.
 *
 * IT IS NOT VALIDATED AGAINST THE BALANCE HERE. That check belongs inside the
 * action's transaction, under the row lock — a request-level check would read a
 * balance that another admin's payout could invalidate before this one commits.
 */
final class CreatePayoutRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->actor()?->type === UserType::Admin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'seller_id' => ['required', 'uuid'],
            // Integer kuruş. `min:1` rather than `min:0`: a zero payout would
            // append a ledger entry that says nothing happened.
            'amount_minor' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function sellerOrgUuid(): string
    {
        return (string) $this->validated('seller_id');
    }

    public function amountMinor(): int
    {
        return (int) $this->validated('amount_minor');
    }

    public function note(): ?string
    {
        return $this->validated('note');
    }
}
