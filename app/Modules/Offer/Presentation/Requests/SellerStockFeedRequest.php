<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Requests;

/**
 * The stock-only push — the call a seller makes hourly (ADR-076).
 *
 * **NO PRICE FIELD AT ALL**, which is the point of the separate endpoint: an
 * hourly stock feed that could also carry a price is one that will eventually
 * carry a wrong one. @see `SyncSellerStockAction`, which refuses to create an
 * offer for the same reason.
 */
final class SellerStockFeedRequest extends SellerOfferFeedRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.$this->maxBatch()],
            'items.*.gtin' => ['required', 'string', 'max:14'],
            'items.*.stock' => ['required', 'integer', 'min:0'],
        ];
    }
}
