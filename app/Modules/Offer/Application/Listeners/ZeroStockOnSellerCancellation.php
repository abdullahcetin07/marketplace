<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Listeners;

use App\Modules\Offer\Application\Actions\UpdateOfferStockAction;
use App\Modules\Offer\Domain\DTOs\UpdateOfferStockDTO;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;

/**
 * A seller cancelled an order because they cannot fulfil it — so their stock for
 * that variant goes to zero (ADR-057).
 *
 * WHY THE ZERO HAPPENS HERE AND NOT IN ORDER, OR IN INVENTORY. The seller
 * declares stock on the OFFER form (ADR-048); Inventory mirrors it. Zeroing
 * anywhere else would leave the seller's own screen still showing ten while the
 * storefront showed none — two numbers for one fact, and the seller believing the
 * wrong one. Writing it at the source means the existing Offer→Inventory mirror
 * carries it the rest of the way, and every surface agrees because they all read
 * from the same place they always did.
 *
 * THE EVENT IS NOT TYPE-HINTED, the same as this module's Catalog listeners. Offer
 * imports no module — Order included — so the subscription is registered by
 * CLASS-STRING and the payload arrives as a plain object whose `offerUuid` this
 * reads. The dependency is on a NAME and a property, not on a class this module
 * compiles against, and the cost is that a rename in Order breaks it at runtime
 * rather than at build time. Bounded by a feature test that fires the real
 * cancellation and asserts the offer went to zero.
 *
 * IT GOES THROUGH `UpdateOfferStockAction`, not through a direct write, so this
 * zero is indistinguishable from a seller typing 0 on their own form: same audit
 * entry, same `OfferStockChanged` event, same mirror into Inventory, same effect
 * on the buy box. A listener that wrote the column itself would be a second way
 * for stock to change, and the two would drift.
 *
 * IT DOES NOT TOUCH STATUS. The offer stays `Active` with zero stock, because
 * out-of-stock is derived, not a status (ADR-043/045) — so the seller restocks
 * from their normal form and is live again, rather than having to find and undo a
 * pause they did not apply.
 *
 * ONE SELLER'S OFFER, NEVER A VARIANT'S. The event names an OFFER, and only that
 * offer is zeroed: another merchant selling the same variant is untouched, because
 * one seller running out says nothing about anybody else's shelf.
 *
 * @see App\Modules\Offer\Application\Actions\UpdateOfferStockAction
 * @see docs/modules/Offer.md §15
 */
final class ZeroStockOnSellerCancellation
{
    public function __construct(
        private readonly UpdateOfferStockAction $updateStock,
    ) {}

    public function handle(object $event): void
    {
        $offerUuid = $event->offerUuid ?? null;

        if (! is_string($offerUuid) || $offerUuid === '') {
            // Reached by name, so a wrong or renamed payload is a live
            // possibility: doing nothing beats a fatal on somebody else's event.
            return;
        }

        $offer = Offer::query()->where('uuid', $offerUuid)->first();

        if ($offer === null) {
            // Withdrawn between the order and the cancellation — soft-deleted, so
            // the default scope hides it. Nothing to zero: it already sells
            // nothing.
            return;
        }

        /*
        | ALREADY ZERO IS NOTHING TO DO. Re-writing it would put a second movement
        | with a zero delta into the seller's stock ledger, which is the one place
        | they go to understand where their stock went (ADR-050).
        */
        if ($offer->stock_quantity === 0) {
            return;
        }

        /*
        | A SUSPENDED OR WITHDRAWN OFFER REFUSES THE WRITE (the action's own rule),
        | and that refusal is correct rather than something to work around: neither
        | sells anything, so there is no oversell to prevent, and forcing a write
        | would edit an offer its seller is not allowed to touch.
        */
        if ($offer->status === OfferStatus::Suspended || $offer->status === OfferStatus::Withdrawn) {
            return;
        }

        $this->updateStock->run($offer, new UpdateOfferStockDTO(
            stockQuantity: 0,
            // The audit trail says WHY the seller's stock went to zero without
            // anybody having typed it — otherwise this reads as an unexplained
            // edit on their own record.
            reason: __('offer.stock.zeroed_by_seller_cancellation', [
                'order' => $event->orderNumber ?? '—',
            ]),
        ));
    }
}
