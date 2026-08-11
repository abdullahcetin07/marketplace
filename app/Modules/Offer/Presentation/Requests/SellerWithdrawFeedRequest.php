<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Requests;

/**
 * Taking offers off sale (ADR-076) — barcodes and nothing else.
 */
final class SellerWithdrawFeedRequest extends SellerOfferFeedRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.$this->maxBatch()],
            'items.*.gtin' => ['required', 'string', 'max:14'],
        ];
    }
}
