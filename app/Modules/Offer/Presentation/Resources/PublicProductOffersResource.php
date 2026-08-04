<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Resources;

use App\Core\Presentation\Support\MoneyString;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The buyer-facing "product + its offers" payload (§5) — the shape the buy box
 * arrives in.
 *
 * A STRICT ALLOW-LIST, the same discipline as `PublicStoreResource` (ADR-034).
 * Everything here is a permanent public promise; internal ids, the selling
 * organization's internal id, stock counts and every private column are absent
 * by construction rather than by omission — the rows this formats already came
 * through `OfferQueryContract`, which returns uuids and nothing else.
 *
 * MONEY IS A DECIMAL STRING PAIRED WITH ITS CURRENCY (005 §28). `price_minor`
 * never leaves; a JSON number would be parsed as a float by most clients and
 * undo the point of integer storage.
 *
 * `featured` IS THE BUY BOX and `other_sellers` is everyone else, already
 * ordered — the client renders, it does not rank. Computing the winner on the
 * client would make the rule unauditable and let two pages disagree about who
 * won.
 *
 * STOCK IS A BOOLEAN, NEVER A COUNT. "3 left" is a merchandising decision with
 * competitive consequences (it tells a rival exactly what a seller holds), and
 * it is not one this sprint gets to make silently by leaking the column.
 *
 * THE SELLER IS NAMED HERE, unlike on the batch price endpoint, and the
 * difference is deliberate: this is ONE product's sellers, at the pace a shopper
 * reads them. The listing endpoint refuses the same information because a hundred
 * winners in one request is a scrape of who is cheapest on everything.
 *
 * `store` REPLACED THE BARE `store_id` (2026-08-01). "Satıcı:
 * a1086566-10aa-4f6f-…" is what a uuid renders as, and a buy box whose seller row
 * cannot say who the seller is fails at its one job. The uuid is still there as
 * `store.id` — nothing was removed, it was given a name to stand next to.
 *
 * @see docs/modules/Offer.md §5
 */
final class PublicProductOffersResource extends JsonResource
{
    /**
     * @param array{uuid: string, title: string, brand: string|null, category: string}|null $product
     * @param array<int, array<string, mixed>> $offers
     * @param array<string, array{name: string, city: string|null}> $stores keyed by store uuid
     */
    public function __construct(
        private readonly ?array $product,
        private readonly array $offers,
        private readonly array $stores = [],
        private readonly int $currencyDecimals = 2,
    ) {
        parent::__construct($offers);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $featured = $this->offers[0] ?? null;
        $others = array_slice($this->offers, 1);

        return [
            'product' => $this->product === null ? null : [
                'id' => $this->product['uuid'],
                'title' => $this->product['title'],
                'brand' => $this->product['brand'],
                'category' => $this->product['category'],
            ],
            // Null when nothing is sellable: the product exists and has sellers,
            // but none of them can take an order right now. A client shows
            // "tükendi" rather than an empty buy box it has to interpret.
            'featured' => $featured === null ? null : $this->offer($featured),
            'other_sellers' => array_map(fn (array $offer): array => $this->offer($offer), $others),
            'offer_count' => count($this->offers),
        ];
    }

    /**
     * @param array<string, mixed> $offer
     *
     * @return array<string, mixed>
     */
    private function offer(array $offer): array
    {
        $storeUuid = (string) $offer['store_uuid'];
        $store = $this->stores[$storeUuid] ?? null;

        return [
            'id' => $offer['uuid'],
            'variant_id' => $offer['variant_uuid'],
            'seller_id' => $offer['selling_org_uuid'],
            /*
            | The shop a buyer is buying FROM, named.
            |
            | `name` is nullable in shape though never null in practice — the
            | profile lookup only returns live stores, and only live stores have
            | eligible offers, so the two sets agree. Rendering null rather than
            | omitting the offer keeps a race (a shop suspended between the two
            | reads) as a missing label instead of a vanishing seller.
            |
            | `city` is null on every store today: it is read from the store's
            | contact address, and no seller-facing form writes one yet. The field
            | is here so the shape does not change again the day that form ships.
            */
            'store' => [
                'id' => $storeUuid,
                'name' => $store['name'] ?? null,
                'city' => $store['city'] ?? null,
            ],
            'price' => MoneyString::from((int) $offer['price_minor'], $this->currencyDecimals),
            'list_price' => $offer['list_price_minor'] === null
                ? null
                : MoneyString::from((int) $offer['list_price_minor'], $this->currencyDecimals),
            'currency' => $offer['currency_code'],
            'in_stock' => (bool) $offer['in_stock'],
        ];
    }
}
