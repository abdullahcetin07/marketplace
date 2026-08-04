<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Resources;

use App\Core\Presentation\Support\MoneyString;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product's buy-box price, for a listing (ADR-058, Storefront.md §1.2).
 *
 * MONEY IS A DECIMAL STRING (005 §28). `price_minor` never leaves: most clients
 * parse a JSON number as a float, which undoes the point of integer storage — and
 * on a listing that is every price on the page.
 *
 * KEYED BY PRODUCT UUID, so a client zips it against the cards it already has
 * without matching on order or position.
 *
 * A PRODUCT WITH NO SELLABLE OFFER IS SIMPLY ABSENT, not present with a null
 * price. Two reasons: a caller iterating the result gets only things it can render
 * a price for, and an unknown uuid looks exactly like an unsold one — so this
 * endpoint cannot be used to probe which product uuids exist.
 *
 * `seller_count` AND `list_price` JOINED IT FOR THE CARD (2026-08-01) — "N satıcı"
 * and a struck-through price, the two things a listing tile shows besides the
 * price itself. Both belong here rather than on a second endpoint: a card that
 * needs three round trips renders three times.
 *
 * NO DISCOUNT PERCENTAGE, deliberately. The struck price and the live price are
 * both facts a seller declared; "%38 indirim" is a claim computed FROM them, and
 * computing it here would make this endpoint the authority on how a discount is
 * rounded and when it is worth showing. A client that wants the badge has both
 * numbers and can decide.
 */
final class BuyBoxPricesResource extends JsonResource
{
    /**
     * @param array<string, array{price_minor: int, list_price_minor: int|null, currency_code: string, in_stock: bool, seller_count: int}> $prices
     * @param array<string, int> $decimalsByCurrency
     */
    public function __construct(
        private readonly array $prices,
        private readonly array $decimalsByCurrency = [],
    ) {
        parent::__construct($prices);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(Request $request): array
    {
        $payload = [];

        foreach ($this->prices as $productUuid => $price) {
            $decimals = $this->decimalsByCurrency[$price['currency_code']] ?? 2;

            $payload[$productUuid] = [
                // "₺X'den başlayan fiyatlarla" — the cheapest sellable offer, which
                // is the same winner the product page's buy box shows. Two surfaces
                // quoting different prices for one product is the worst outcome
                // available here, so both compute it the same way (ADR-045).
                'from_price' => MoneyString::from($price['price_minor'], $decimals),
                /*
                | The WINNING seller's "was" price, for the strike-through — null
                | when they declared none, which is most offers. Not the product's:
                | on a shared catalogue there is no such number, only this
                | merchant's claim about what they discounted from (ADR-042).
                */
                'list_price' => $price['list_price_minor'] === null
                    ? null
                    : MoneyString::from($price['list_price_minor'], $decimals),
                'currency' => $price['currency_code'],
                'in_stock' => $price['in_stock'],
                // Merchants a buyer could choose between, not offers — one seller
                // listing three sizes is one choice (see the contract).
                'seller_count' => $price['seller_count'],
            ];
        }

        return $payload;
    }
}
