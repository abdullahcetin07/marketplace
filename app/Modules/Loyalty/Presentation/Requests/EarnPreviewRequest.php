<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;

/**
 * The amount a shopper is looking at, on its way to becoming points (ADR-082).
 */
final class EarnPreviewRequest extends BaseRequest
{
    /**
     * **PUBLIC.** `BaseRequest::authorize()` defaults to false, so saying so is
     * deliberate rather than an omission: the product page is public and the
     * signed-out shopper is the one this card is arguing with.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
            | **A DECIMAL STRING, AND A COMMA IS ONE** — Turkish writes 129,90 and a
            | hand-typed or copy-pasted URL will carry it, exactly as the seller feed
            | accepts (ADR-076). A negative amount is refused rather than floored to
            | zero: it is a caller bug, and answering "0 points" would hide it.
            */
            'amount' => ['required', 'string', 'max:20', 'regex:/^\d+([.,]\d+)?$/'],
        ];
    }

    /**
     * The amount in kuruş — converted once, at the boundary (ADR-005).
     */
    public function amountMinor(): int
    {
        $amount = str_replace(',', '.', (string) $this->input('amount'));

        return (int) round(((float) $amount) * 100);
    }
}
