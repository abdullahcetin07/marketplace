<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Resources;

use App\Core\Presentation\Support\MoneyString;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The basket, priced live (§2.1, §4).
 *
 * MONEY IS A DECIMAL STRING (005 §28). `..._minor` never leaves: most clients
 * parse a JSON number as a float, which undoes the point of integer storage.
 *
 * IT SHOWS THE SPLIT BEFORE CHECKOUT, not after. "This will arrive as 2 separate
 * deliveries" is something a shopper should learn while they can still change
 * their mind (ADR-052) — a confirmation screen is too late for it to be useful.
 *
 * AN UNSELLABLE LINE IS PRESENT AND FLAGGED, with null money. Dropping it would
 * leave a customer wondering what happened to the thing they chose, and the cart
 * never stored a price to fall back on.
 *
 * NO TAX BREAKDOWN, deliberately: KDV is extracted per line at PLACEMENT from the
 * product's bracket (§3.4), and a number computed here might not be the one the
 * order reproduces. A basket shows what it costs; an order shows what it is made
 * of.
 */
final class CartResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $priced  the CartPricingService payload
     */
    public function __construct(
        private readonly array $priced,
        private readonly int $currencyDecimals = 2,
    ) {
        parent::__construct($priced);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'items' => array_map(fn (array $line): array => $this->line($line), $this->priced['lines']),
            'sellers' => array_map(fn (array $group): array => [
                'seller_id' => $group['selling_org_uuid'],
                'store_id' => $group['store_uuid'],
                'line_count' => $group['line_count'],
                'items_total' => MoneyString::from((int) $group['items_total_minor'], $this->currencyDecimals),
            ], $this->priced['groups']),
            'items_total' => MoneyString::from((int) $this->priced['items_total_minor'], $this->currencyDecimals),
            'currency' => $this->priced['currency_code'],
            // The client's cue to re-render with a warning rather than to offer a
            // checkout that will certainly fail.
            'has_unavailable_items' => $this->priced['has_unsellable_lines'],
            // How many orders this becomes (ADR-052), stated rather than counted
            // client-side from the groups.
            'order_count' => count($this->priced['groups']),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function line(array $line): array
    {
        $unit = $line['unit_price_minor'];
        $total = $line['line_total_minor'];

        return [
            'id' => $line['uuid'],
            'offer_id' => $line['offer_uuid'],
            'product_id' => $line['product_uuid'],
            'variant_id' => $line['variant_uuid'],
            'seller_id' => $line['selling_org_uuid'],
            'store_id' => $line['store_uuid'],
            'title' => $line['title'],
            'quantity' => $line['quantity'],
            // Null, not zero, when the offer is gone — an amount of zero would
            // read as free.
            'unit_price' => $unit === null ? null : MoneyString::from((int) $unit, $this->currencyDecimals),
            'line_total' => $total === null ? null : MoneyString::from((int) $total, $this->currencyDecimals),
            'currency' => $line['currency_code'],
            'available' => $line['sellable'],
        ];
    }
}
