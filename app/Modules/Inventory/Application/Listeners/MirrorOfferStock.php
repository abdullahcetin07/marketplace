<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Listeners;

use App\Modules\Inventory\Application\Actions\AdjustStockAction;
use App\Modules\Inventory\Domain\DTOs\AdjustStockDTO;

/**
 * Inventory's ear on the Offer's stock field (§3.1, ADR-048).
 *
 * THE EVENT IS NOT TYPE-HINTED, AND THAT IS THE POINT. Inventory imports no
 * module — Offer included, with no events escape hatch (`LayeringTest`) — so the
 * subscription is registered by CLASS-STRING in the service provider and each
 * payload arrives as a plain object whose public properties this reads. The
 * dependency is on NAMES, not on classes this module compiles against; it is the
 * same coupling Offer uses for Catalog's product lifecycle, and it is why the
 * approval anticipated adding fields to those events rather than importing them
 * (§10.4).
 *
 * ONE HANDLER FOR CREATE AND CHANGE, because they are the same statement: "the
 * seller says they have N". `AdjustStockAction` takes an absolute quantity and
 * computes the delta, so it creates the pool on the first event and converges on
 * the seller's number on every one after — a replayed or out-of-order event
 * cannot compound.
 *
 * WITHDRAWAL ZEROES ON-HAND rather than deleting the pool. The listing is gone
 * but the ledger is evidence, and a seller who re-lists tomorrow should find
 * their history rather than a fresh row. Reservations against it, if any, remain
 * the caller's to release — Inventory will not silently cancel somebody else's
 * checkout, and `available` reads zero either way.
 *
 * THE COST, stated rather than hidden: a rename of an Offer event class, or of
 * one of the properties read below, breaks this at RUNTIME rather than at build
 * time. It is bounded by a feature test that fires the real Offer actions and
 * asserts the mirror moved.
 *
 * @see App\Modules\Inventory\Application\Actions\AdjustStockAction
 */
final class MirrorOfferStock
{
    public function __construct(
        private readonly AdjustStockAction $adjust,
    ) {}

    /**
     * `OfferCreated` and `OfferStockChanged` — both carry the seller's declared
     * quantity as `stockQuantity`.
     */
    public function onStockDeclared(object $event): void
    {
        $data = $this->payload($event);

        if ($data === null) {
            return;
        }

        $this->adjust->run(new AdjustStockDTO(
            variantUuid: $data['variant'],
            productUuid: $data['product'],
            sellingOrgId: $data['orgId'],
            sellingOrgUuid: $data['orgUuid'],
            onHand: (int) ($event->stockQuantity ?? 0),
            offerUuid: $data['offer'],
            note: __('inventory.movement.mirrored_from_offer'),
        ));
    }

    /**
     * `OfferWithdrawn` — the listing is gone, so nothing is sellable through it.
     */
    public function onWithdrawn(object $event): void
    {
        $data = $this->payload($event);

        if ($data === null) {
            return;
        }

        $this->adjust->run(new AdjustStockDTO(
            variantUuid: $data['variant'],
            productUuid: $data['product'],
            sellingOrgId: $data['orgId'],
            sellingOrgUuid: $data['orgUuid'],
            onHand: 0,
            offerUuid: $data['offer'],
            note: __('inventory.movement.offer_withdrawn'),
        ));
    }

    /**
     * Read the four identifiers off an untyped payload, or refuse.
     *
     * Reached BY NAME, so a wrong event class is a live possibility rather than
     * a compile error — returning null on a payload that does not carry what
     * this needs is the difference between a dropped mirror update and a fatal
     * on somebody else's event.
     *
     * @return array{variant: string, product: string, orgId: int, orgUuid: string, offer: string|null}|null
     */
    private function payload(object $event): ?array
    {
        $variant = $event->variantUuid ?? null;
        $product = $event->productUuid ?? null;
        $orgId = $event->sellingOrgId ?? null;
        $orgUuid = $event->sellingOrgUuid ?? null;

        if (! is_string($variant) || $variant === ''
            || ! is_string($product) || $product === ''
            || ! is_int($orgId)
            || ! is_string($orgUuid) || $orgUuid === '') {
            return null;
        }

        $offer = $event->offerUuid ?? null;

        return [
            'variant' => $variant,
            'product' => $product,
            'orgId' => $orgId,
            'orgUuid' => $orgUuid,
            'offer' => is_string($offer) ? $offer : null,
        ];
    }
}
