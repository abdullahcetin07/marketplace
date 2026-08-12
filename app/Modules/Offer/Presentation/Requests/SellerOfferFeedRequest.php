<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Shared\Enums\UserType;

/**
 * One POST of feed items (ADR-076).
 *
 * **MONEY CROSSES THE WIRE AS A DECIMAL STRING AND BECOMES KURUŞ HERE** — at the
 * boundary, once, through the Currency model (ADR-005). Past this class the amount
 * is an integer and nothing downstream can reintroduce a float.
 *
 * **THE BATCH CEILING IS A 422, NOT A TRUNCATION.** Silently processing the first
 * 500 of 4,000 items would tell a seller's system everything succeeded while three
 * quarters of their catalogue went nowhere. Refusing is the only answer a machine
 * can act on.
 *
 * **THERE IS NO ORGANIZATION FIELD, AND THAT IS THE AUTHORIZATION MODEL.** The
 * acting merchant is resolved from the token's user (@see `SellerFeedIdentity`), so
 * the payload has nowhere to name somebody else's shop.
 *
 * SELLERS ONLY. `BaseRequest::authorize()` defaults to false; an admin or customer
 * token is refused here before any item is read.
 */
class SellerOfferFeedRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->actor()?->type === UserType::Seller;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.$this->maxBatch()],
            'items.*.gtin' => ['required', 'string', 'max:14'],
            /*
            | A DECIMAL STRING, not a number: JSON floats are the exact thing the
            | minor-units rule exists to keep out, and `129.90` arriving as
            | 129.89999999999998 is a price nobody typed.
            |
            | **MORE THAN TWO DECIMALS IS ACCEPTED AND ROUNDED TO THE KURUŞ**, the
            | same as the CSV door. A stock system that derives prices from a
            | KDV-exclusive base legitimately emits `494.244`, and a seller whose
            | ERP does that could push nothing at all while the identical row
            | imported by spreadsheet went through — two doors, one brain, and
            | they disagreed about what a price is. What is still refused is a
            | string that is not a number: `toMinor()` reads `12.5.6` as 12,50 TL
            | and `abc` as zero, so the shape has to be decided here.
            */
            'items.*.price' => [
                'required', 'string', 'max:20', 'regex:/^\d+([.,]\d+)?$/',
            ],
            'items.*.stock' => ['required', 'integer', 'min:0'],
            'items.*.list_price' => [
                'nullable', 'string', 'max:20', 'regex:/^\d+([.,]\d+)?$/',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.max' => __('offer.feed.batch_too_large', ['max' => $this->maxBatch()]),
        ];
    }

    /**
     * The items, with money already in minor units.
     *
     * @return array<int, array{gtin: string, price: ?int, stock: ?int, list_price: ?int}>
     */
    public function items(): array
    {
        $currency = app(CurrencyRepositoryContract::class)->default();

        /** @var array<int, array<string, mixed>> $items */
        $items = $this->validated('items');

        return array_map(static fn (array $item): array => [
            'gtin' => trim((string) $item['gtin']),
            'price' => isset($item['price'])
                // A COMMA IS A DECIMAL POINT IN TURKISH and Excel writes it that
                // way; normalised here so the seller's locale is not a rejection.
                ? $currency->toMinor(str_replace(',', '.', (string) $item['price']))
                : null,
            'stock' => isset($item['stock']) ? (int) $item['stock'] : null,
            // `isset()` ALREADY EXCLUDES NULL — a second `!== null` beside it reads
            // as though one of them were doing something the other is not.
            'list_price' => isset($item['list_price'])
                ? $currency->toMinor(str_replace(',', '.', (string) $item['list_price']))
                : null,
        ], $items);
    }

    protected function maxBatch(): int
    {
        return (int) config('offer.feed.max_batch', 500);
    }
}
