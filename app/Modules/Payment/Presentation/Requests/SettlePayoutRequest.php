<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Payment\Domain\Enums\PayoutStatus;
use App\Shared\Enums\UserType;

/**
 * Recording what the bank did (Payment.md §8).
 *
 * ONE REQUEST FOR BOTH OUTCOMES. `outcome` is `paid` or `failed`, and `detail` is
 * the reference in the first case and the reason in the second — because they are
 * the same field from the operator's side: "what did the bank tell you".
 *
 * `pending` IS NOT AN ACCEPTED OUTCOME. This endpoint records an answer; there is
 * no answer that means "still waiting", and allowing one would let a settled
 * payout be walked backwards.
 */
final class SettlePayoutRequest extends BaseRequest
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
            'outcome' => ['required', 'string', 'in:paid,failed'],
            // The bank's reference when paid, its refusal when failed.
            'detail' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function outcome(): PayoutStatus
    {
        return PayoutStatus::from((string) $this->validated('outcome'));
    }

    public function detail(): ?string
    {
        return $this->validated('detail');
    }
}
