<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Services;

use App\Core\Domain\Contracts\CatalogBrowseContract;
use App\Core\Domain\Contracts\OfferQueryContract;
use App\Modules\Order\Domain\Models\Cart;
use App\Modules\Order\Domain\Models\CartItem;

/**
 * What the basket costs RIGHT NOW (§2.1).
 *
 * THE COUNTERPART TO THE CART STORING NO PRICES. Every amount here is read live
 * from the Offer at the moment it is asked for — so a seller who re-prices at
 * 14:00 changes what the basket says at 14:01, which is the correct behaviour for
 * a basket and the exact opposite of the correct behaviour for an order
 * (ADR-053). Those two rules living in different classes is not an accident; it
 * is the boundary.
 *
 * A SERVICE, NOT AN ACTION: it owns no transaction and writes nothing (the "Action
 * or service?" rule). It cannot be named with one verb and one noun because it
 * does several related reads — price the lines, group them by seller, total them.
 *
 * IT REPORTS UNSELLABLE LINES RATHER THAN HIDING OR REMOVING THEM. A seller
 * pausing an offer while it sits in someone's basket is ordinary, and silently
 * dropping the line would leave a customer wondering what happened to the thing
 * they chose. Checkout is where it becomes a refusal (§3.1); here it is a flag the
 * client renders as "no longer available".
 *
 * TITLES COME FROM THE CATALOG, per read, and are NOT cached: a cart is rendered a
 * handful of times a session and `productSummaries()` takes the whole batch in one
 * call, so the saving would be invisible and the staleness would not.
 *
 * MONEY LEAVES AS INTEGER MINOR UNITS (non-negotiable #6). Rendering decimal
 * strings is the API layer's job (005 §28).
 *
 * @see docs/modules/Order.md §2.1
 */
final class CartPricingService
{
    public function __construct(
        private readonly OfferQueryContract $offers,
        private readonly CatalogBrowseContract $catalog,
    ) {}

    /**
     * The priced basket: every line with its live price, grouped by the seller it
     * will become an order for (ADR-052).
     *
     * @return array{
     *     lines: array<int, array<string, mixed>>,
     *     groups: array<int, array<string, mixed>>,
     *     items_total_minor: int,
     *     currency_code: string|null,
     *     has_unsellable_lines: bool,
     * }
     */
    public function price(Cart $cart): array
    {
        $titles = $this->titlesFor($cart);

        $lines = [];
        $itemsTotal = 0;
        $currency = null;
        $hasUnsellable = false;

        foreach ($cart->items as $item) {
            $offer = $this->offers->activeOfferByUuid($item->offer_uuid);

            if ($offer === null) {
                $hasUnsellable = true;
                $lines[] = $this->unsellableLine($item, $titles);

                continue;
            }

            $unit = (int) $offer['price_minor'];
            $total = $unit * $item->quantity;
            $itemsTotal += $total;
            $currency ??= (string) $offer['currency_code'];

            $lines[] = [
                'uuid' => $item->uuid,
                'offer_uuid' => $item->offer_uuid,
                'product_uuid' => $item->product_uuid,
                'variant_uuid' => $item->variant_uuid,
                'selling_org_uuid' => $item->selling_org_uuid,
                'store_uuid' => $item->store_uuid,
                'title' => $titles[$item->product_uuid] ?? null,
                'quantity' => $item->quantity,
                'unit_price_minor' => $unit,
                'line_total_minor' => $total,
                'currency_code' => (string) $offer['currency_code'],
                'sellable' => true,
            ];
        }

        return [
            'lines' => $lines,
            'groups' => $this->groups($lines),
            // KDV-INCLUDED (ADR-042), so this is what the customer would pay for
            // the goods. The tax BREAKDOWN is deliberately absent: it is extracted
            // per line at placement from the product's bracket (§3.4), and
            // computing it here would mean showing a number the order might not
            // reproduce.
            'items_total_minor' => $itemsTotal,
            'currency_code' => $currency,
            'has_unsellable_lines' => $hasUnsellable,
        ];
    }

    /**
     * The basket as the sellers will see it — one group per seller, which is one
     * order after checkout (ADR-052).
     *
     * SHOWN BEFORE CHECKOUT, not after, because "this will arrive as 2 separate
     * deliveries" is a thing a shopper should learn while they can still change
     * their mind, not on the confirmation screen.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function groups(array $lines): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $org = (string) $line['selling_org_uuid'];

            $groups[$org] ??= [
                'selling_org_uuid' => $org,
                'store_uuid' => $line['store_uuid'],
                'items_total_minor' => 0,
                'line_count' => 0,
            ];

            $groups[$org]['items_total_minor'] += (int) ($line['line_total_minor'] ?? 0);
            $groups[$org]['line_count']++;
        }

        return array_values($groups);
    }

    /**
     * A line whose offer is no longer sellable — kept visible, with no price.
     *
     * NULL RATHER THAN THE LAST KNOWN PRICE, because there is no last known price:
     * the cart never stored one, and inventing one here would be the stale copy
     * §2.1 exists to avoid.
     *
     * @param  array<string, string>  $titles
     * @return array<string, mixed>
     */
    private function unsellableLine(CartItem $item, array $titles): array
    {
        return [
            'uuid' => $item->uuid,
            'offer_uuid' => $item->offer_uuid,
            'product_uuid' => $item->product_uuid,
            'variant_uuid' => $item->variant_uuid,
            'selling_org_uuid' => $item->selling_org_uuid,
            'store_uuid' => $item->store_uuid,
            'title' => $titles[$item->product_uuid] ?? null,
            'quantity' => $item->quantity,
            'unit_price_minor' => null,
            'line_total_minor' => null,
            'currency_code' => null,
            'sellable' => false,
        ];
    }

    /**
     * Product titles for the whole basket in ONE call — the reason
     * `CatalogBrowseContract::productSummaries()` takes a batch.
     *
     * @return array<string, string>
     */
    private function titlesFor(Cart $cart): array
    {
        $uuids = $cart->items->pluck('product_uuid')->unique()->values()->all();

        if ($uuids === []) {
            return [];
        }

        $titles = [];

        foreach ($this->catalog->productSummaries($uuids) as $uuid => $summary) {
            $titles[(string) $uuid] = (string) ($summary['title'] ?? '');
        }

        return $titles;
    }
}
