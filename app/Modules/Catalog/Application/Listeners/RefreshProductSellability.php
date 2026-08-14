<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Listeners;

use App\Modules\Catalog\Application\Services\ProductSellability;

/**
 * Keeps the browse's `is_sellable` flag current (ADR-079).
 *
 * **BY CLASS-STRING, IN BOTH DIRECTIONS.** The events come from Offer and from
 * Inventory and Catalog imports neither — the same seam Inventory uses to hear
 * Offer's stock events (ADR-048). The handlers below read `$event->productUuid`
 * and `$event->variantUuid` off objects whose classes are never named here, which
 * is exactly what makes the boundary hold.
 *
 * **TWO KINDS OF SIGNAL, BECAUSE SELLABILITY HAS TWO HALVES.** An offer can stop
 * being sellable because the seller withdrew it, and it can stop because the last
 * unit went into somebody else's basket — the first is Offer's news, the second
 * Inventory's, and a listener that heard only one would leave the storefront
 * confidently wrong half the time.
 *
 * **IT RECOMPUTES RATHER THAN INFERRING.** An `OfferWithdrawn` does not mean the
 * product is unsellable: another merchant may still be selling it. The event says
 * "look again at this product", and the answer comes from the same Core call the
 * sweep uses.
 *
 * @see App\Modules\Catalog\Application\Services\ProductSellability
 */
final class RefreshProductSellability
{
    public function __construct(private readonly ProductSellability $sellability) {}

    /**
     * Offer's news: created, stock declared, withdrawn, paused, resumed,
     * suspended, reinstated. Each carries the product it is about.
     */
    public function onOfferChanged(object $event): void
    {
        $productUuid = $event->productUuid ?? null;

        if (is_string($productUuid)) {
            $this->sellability->refresh([$productUuid]);
        }
    }

    /**
     * Inventory's news: a reservation taken or released, a commit, a restock, an
     * adjustment. These speak (org, variant) because that is what a stock pool is
     * (ADR-051), so the variant is translated to its product here — Catalog owns
     * variants, and asking Inventory to carry a product uuid would put somebody
     * else's model in its events.
     */
    public function onStockMoved(object $event): void
    {
        $variantUuid = $event->variantUuid ?? null;

        if (! is_string($variantUuid)) {
            return;
        }

        $this->sellability->refresh(
            $this->sellability->productsOfVariants([$variantUuid]),
        );
    }
}
