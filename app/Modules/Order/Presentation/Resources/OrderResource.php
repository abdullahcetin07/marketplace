<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Resources;

use App\Core\Presentation\Support\MoneyString;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One seller's order (§2.3, §4).
 *
 * IT CARRIES `checkout_group_id` PROMINENTLY, because without it a customer's
 * order list is N unexplained rows for what they remember as one purchase
 * (ADR-052). That field is how a client groups them back together, and it is the
 * single most important consequence of the split for anyone consuming this API.
 *
 * MONEY IS DECIMAL STRINGS THROUGHOUT (005 §28), and the TAX BREAKDOWN IS
 * PRESENT — unlike the cart's. Prices are KDV-included (ADR-042), so `items_total`
 * already contains `tax_total`; showing both is what makes the order a document
 * an invoice can be produced from, and each line carries the rate that produced
 * its own tax (ADR-053/055).
 *
 * ADDRESSES ARE THE SNAPSHOTS, not the address book. What is returned is what was
 * agreed, forever — a customer who has since moved sees where this parcel went,
 * which is the whole point of freezing them (ADR-056).
 *
 * NO INTERNAL IDS ANYWHERE, and no seller internal id either: the seller crosses
 * as its uuid (ADR-040).
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $decimals = $this->currency->decimal_places;

        return [
            'id' => $this->uuid,
            // The human handle — what a customer quotes in an email (§2.3).
            'number' => $this->order_number,
            'checkout_group_id' => $this->checkout_group_uuid,
            'status' => $this->status->value,
            'seller_id' => $this->selling_org_uuid,
            'store_id' => $this->store_uuid,
            /*
            | THE SHOP, NAMED AND LINKABLE (2026-08-06). `store_id` alone can only
            | be shown; this can be followed — the store page is path-addressed at
            | `/magaza/{slug}` (ADR-035).
            |
            | NULL WHEN THE SHOP IS NOT LIVE, because the profile read behind it is
            | live-only: a suspended seller's order is shown without a link rather
            | than linking to a page that will not load. Null also when the order
            | did not pass through the controller's batch resolver, which is why
            | this reads the RAW attribute bag — strict mode throws on a missing
            | attribute, and a resource is the wrong place to discover that.
            */
            'store' => $this->resource->getAttributes()['store_profile'] ?? null,

            'items_total' => MoneyString::from($this->items_total_minor, $decimals),
            'tax_total' => MoneyString::from($this->tax_total_minor, $decimals),
            'grand_total' => MoneyString::from($this->grand_total_minor, $decimals),
            'currency' => $this->currency->code,

            'shipping_address' => $this->shipping_address,
            'billing_address' => $this->billing_address,

            'lines' => $this->whenLoaded('lines', fn (): array => $this->lines
                ->map(fn (OrderLine $line): array => $this->line($line, $decimals))
                ->all()),

            'placed_at' => $this->placed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(OrderLine $line, int $decimals): array
    {
        return [
            'id' => $line->uuid,
            'offer_id' => $line->offer_uuid,
            'product_id' => $line->product_uuid,
            'variant_id' => $line->variant_uuid,
            // THE SNAPSHOT (ADR-053): the title as it was, not as the catalog
            // reads today.
            'title' => $line->product_title,
            'variant' => $line->variant_label,
            'quantity' => $line->quantity,
            'unit_price' => MoneyString::from($line->unit_price_minor, $decimals),
            'line_total' => MoneyString::from($line->line_total_minor, $decimals),
            // The rate that produced the tax beside it — an invoice must be able
            // to show the pair, and a rate looked up later could no longer match.
            'tax_rate' => $line->tax_rate,
            'line_tax' => MoneyString::from($line->line_tax_minor, $decimals),
        ];
    }
}
